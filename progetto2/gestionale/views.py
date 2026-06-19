from __future__ import annotations

from datetime import timedelta
from decimal import Decimal
from itertools import chain

from django.contrib import messages
from django.db import transaction
from django.db.models import Case, Exists, IntegerField, Max, OuterRef, Q, Subquery, Sum, Value, When
from django.http import Http404, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.template.loader import render_to_string
from django.urls import reverse
from django.utils import timezone

from .excel import spreadsheet_response
from .forms import SIMDisattivaForm
from .models import (
    ContrattoTelefonico,
    SIMAttiva,
    SIMDisattiva,
    SIMNonAttiva,
    StatisticheContratto,
    StatisticheSIM,
    StatisticheTelefonate,
    Telefonata,
)
from .services import call_queryset, contract_queryset, page_window, sim_rows

PAGE_SIZE = 12
CALL_EXPORT_LIMIT = 50_000


def _is_xhr(request) -> bool:
    return request.headers.get("X-Requested-With", "").lower() == "xmlhttprequest"


def _call_row_filters_active(filters: dict) -> bool:
    return any(
        str(filters.get(name, "")).strip()
        for name in (
            "data_da", "data_a", "ora_da", "ora_a",
            "durata_preset", "durata_ore", "durata_min", "durata_sec",
            "costo_max",
        )
    )


def _fast_contract_scoped_call_count(filters: dict) -> int:
    """Conta le chiamate tramite le statistiche per contratto.

    È il percorso veloce usato quando i filtri riguardano soltanto numero,
    stato o piano. Evita di scandire la tabella Telefonata, che può contenere
    milioni di righe.
    """

    scope = ContrattoTelefonico.objects.all()
    number = str(filters.get("contratto", "")).strip()
    state = str(filters.get("stato_numero", "")).strip()
    plan = str(filters.get("piano", "")).strip()

    if number:
        scope = scope.filter(numero=number) if len(number) >= 10 else scope.filter(numero__startswith=number)
    if state:
        scope = scope.annotate(
            has_active_sim=Exists(SIMAttiva.objects.filter(associataA=OuterRef("pk")))
        )
        if state == "attivo":
            scope = scope.filter(has_active_sim=True)
        elif state == "disattivato":
            scope = scope.filter(has_active_sim=False, sim_disattive__isnull=False).distinct()
    if plan:
        scope = scope.filter(tipo=plan)

    result = StatisticheContratto.objects.filter(
        numero_id__in=Subquery(scope.values("numero"))
    ).aggregate(total=Sum("numeroTelefonate"))["total"]
    return int(result or 0)


def _lazy_queryset_block(qs, request, default_limit: int = PAGE_SIZE, allow_reverse: bool = False):
    """Restituisce un blocco lazy senza eseguire COUNT né OFFSET enormi."""

    limit = _safe_int_param(request, "limit", default_limit, 1, 60)
    jump_last = allow_reverse and request.GET.get("jump_last") == "1"

    if jump_last:
        reverse_offset = _safe_int_param(request, "reverse_offset", 0, 0)
        raw = list(qs.reverse()[reverse_offset:reverse_offset + limit + 1])
        has_prev = len(raw) > limit
        rows = raw[:limit]
        rows.reverse()
        return rows, {
            "start": 0,
            "end": len(rows),
            "limit": limit,
            "has_prev": has_prev,
            "has_next": False,
            "next_offset": None,
            "prev_offset": None,
            "reverse_offset": reverse_offset + len(rows),
            "from_end": True,
        }

    offset = _safe_int_param(request, "offset", 0, 0)
    raw = list(qs[offset:offset + limit + 1])
    has_next = len(raw) > limit
    rows = raw[:limit]
    return rows, {
        "start": offset,
        "end": offset + len(rows),
        "limit": limit,
        "has_prev": offset > 0,
        "has_next": has_next,
        "next_offset": offset + len(rows),
        "prev_offset": offset,
        "reverse_offset": 0,
        "from_end": False,
    }


def _safe_page(request) -> int:
    try:
        return max(1, int(request.GET.get("page", "1")))
    except (TypeError, ValueError):
        return 1


def _query_without(request, *keys: str) -> str:
    params = request.GET.copy()
    for key in keys:
        params.pop(key, None)
    return params.urlencode()




def _safe_int_param(request, name: str, default: int = 0, minimum: int = 0, maximum: int | None = None) -> int:
    try:
        value = int(request.GET.get(name, default))
    except (TypeError, ValueError):
        value = default
    value = max(minimum, value)
    if maximum is not None:
        value = min(value, maximum)
    return value


def _lazy_window(total: int, request, default_limit: int = PAGE_SIZE) -> dict[str, int | bool]:
    limit = _safe_int_param(request, "limit", default_limit, 1, 60)
    reverse_offset = _safe_int_param(request, "reverse_offset", 0, 0)
    if request.GET.get("jump_last") == "1":
        start = max(0, total - reverse_offset - limit)
    else:
        start = _safe_int_param(request, "offset", 0, 0)
        if start >= total:
            start = max(0, total - limit)
    end = min(total, start + limit)
    return {
        "start": start,
        "end": end,
        "limit": limit,
        "has_prev": start > 0,
        "has_next": end < total,
        "next_offset": end,
        "prev_offset": start,
        "reverse_offset": reverse_offset + max(0, end - start),
        "from_end": request.GET.get("jump_last") == "1",
    }


def _json_fragments(cards_template: str, table_template: str, context: dict, meta: dict) -> JsonResponse:
    payload = {
        "html": render_to_string(cards_template, context),
        "table_html": render_to_string(table_template, context),
        "has_more": bool(meta["has_next"]),
        "has_prev": bool(meta["has_prev"]),
        "next_offset": meta["next_offset"],
        "prev_offset": meta["prev_offset"],
        "from_end": bool(meta.get("from_end")),
        "reverse_offset": meta.get("reverse_offset", 0),
    }
    if "total_count" in meta:
        payload["total_count"] = meta["total_count"]
    return JsonResponse(payload)

def home(request):
    stats = StatisticheTelefonate.objects.filter(pk=1).first()
    total_calls = stats.totaleTelefonate if stats else Telefonata.objects.count()
    average_duration = int(stats.durataMedia if stats else 0)
    total_cost = stats.addebitoTotale if stats else Decimal("0")

    total_contracts = ContrattoTelefonico.objects.count()
    recharge_contracts = ContrattoTelefonico.objects.filter(tipo="ricarica").count()
    consumption_contracts = total_contracts - recharge_contracts
    active_sim = SIMAttiva.objects.count()
    available_sim = SIMNonAttiva.objects.count()
    disabled_sim = SIMDisattiva.objects.count()

    query = (request.GET.get("ricerca_globale") or "").strip()
    search_error = ""
    phone_results = []
    sim_results = []
    if query:
        if not query.isdigit():
            search_error = "Inserire esclusivamente cifre di un numero telefonico o di un codice SIM."
        else:
            exact_phone = Case(
                When(numero=query, then=Value(0)),
                default=Value(1),
                output_field=IntegerField(),
            )
            phone_qs = (
                ContrattoTelefonico.objects.filter(numero__contains=query)
                .annotate(
                    exact_match=exact_phone,
                    has_active_sim=Exists(SIMAttiva.objects.filter(associataA=OuterRef("pk"))),
                    has_disabled_sim=Exists(SIMDisattiva.objects.filter(eraAssociataA=OuterRef("pk"))),
                )
                .order_by("exact_match", "numero")[:6]
            )
            for item in phone_qs:
                if item.has_active_sim:
                    status = "Numero attivo"
                    is_disabled = False
                elif item.has_disabled_sim:
                    status = "Numero disattivato"
                    is_disabled = True
                else:
                    status = "Nessuna SIM associata"
                    is_disabled = False
                phone_results.append({
                    "numero": item.numero,
                    "tipo": item.get_tipo_display(),
                    "data": item.dataAttivazione,
                    "active": bool(item.has_active_sim),
                    "status": status,
                    "is_disabled": is_disabled,
                })

            candidates = []
            sim_sources = (
                ("attive", "SIM in uso", 1, SIMAttiva.objects.filter(codice__contains=query).select_related("associataA")),
                ("disponibili", "SIM disponibile", 2, SIMNonAttiva.objects.filter(codice__contains=query)),
                ("disattive", "SIM disattivata", 3, SIMDisattiva.objects.filter(codice__contains=query).select_related("eraAssociataA")),
            )
            for state, label, state_order, queryset in sim_sources:
                queryset = queryset.annotate(
                    exact_match=Case(
                        When(codice=query, then=Value(0)),
                        default=Value(1),
                        output_field=IntegerField(),
                    )
                ).order_by("exact_match", "codice")[:8]
                for item in queryset:
                    number = ""
                    if state == "attive":
                        number = item.associataA_id
                    elif state == "disattive":
                        number = item.eraAssociataA_id
                    candidates.append({
                        "codice": item.codice,
                        "state": state,
                        "state_label": label,
                        "tipo": item.get_tipoSIM_display(),
                        "numero": number,
                        "exact": 0 if item.codice == query else 1,
                        "state_order": state_order,
                    })
            candidates.sort(key=lambda row: (row["exact"], row["state_order"], row["codice"]))
            sim_results = candidates[:8]

    latest_disabled = SIMDisattiva.objects.aggregate(latest=Max("dataDisattivazione"))["latest"]
    latest_disabled = latest_disabled or timezone.localdate()
    recent_from = latest_disabled - timedelta(days=29)

    context = {
        "total_telefonate": total_calls,
        "durata_media": average_duration,
        "costo_totale": total_cost,
        "total_contratti": total_contracts,
        "contratti_ricarica": recharge_contracts,
        "contratti_consumo": consumption_contracts,
        "total_sim_attive": active_sim,
        "total_sim_non_attive": available_sim,
        "total_sim_disattive": disabled_sim,
        "total_sim_gestite": active_sim + available_sim + disabled_sim,
        "global_query": query,
        "global_error": search_error,
        "global_phone_results": phone_results,
        "global_sim_results": sim_results,
        "recent_disabled_from": recent_from.isoformat(),
        "recent_disabled_to": latest_disabled.isoformat(),
    }
    return render(request, "index.html", context)

def lista_contratti(request):
    qs, errors, filters = contract_queryset(request.GET)
    if request.GET.get("export") == "excel" and not errors:
        rows = (
            (
                item.numero,
                "Numero attivo" if item.is_active else "Numero disattivato",
                item.latest_disabled_code or "",
                item.latest_disabled_date.strftime("%d/%m/%Y") if item.latest_disabled_date else "",
                item.dataAttivazione.strftime("%d/%m/%Y"),
                item.get_tipo_display(),
                item.minutiResidui if item.tipo == "consumo" else "",
                item.creditoResiduo if item.tipo == "ricarica" else "",
                item.num_calls,
                item.total_duration,
                item.total_cost,
            )
            for item in qs.iterator(chunk_size=500)
        )
        return spreadsheet_response(
            "numeri_telefonici.xls",
            "Numeri telefonici",
            ["Numero", "Stato", "SIM precedente", "Disattivazione SIM", "Attivazione numero", "Piano", "Tempo residuo (minuti)", "Credito residuo (€)", "Chiamate", "Durata totale (secondi)", "Addebiti totali (€)"],
            rows,
            ["right", "left", "right", "right", "right", "left", "right", "right", "right", "right", "right"],
            ["text", "text", "text", "date", "date", "text", "integer", "currency", "integer", "integer", "currency"],
        )

    total = 0 if errors else qs.count()
    if request.GET.get("count_only") == "1":
        return JsonResponse({"total_count": total})
    if request.GET.get("ajax_rows") == "1":
        meta = _lazy_window(total, request, PAGE_SIZE)
        contracts = [] if errors else list(qs[meta["start"]:meta["end"]])
        context = {"contratti": contracts, "total_count": total, "filters": filters, "search_errors": errors}
        meta["total_count"] = total
        return _json_fragments("_contratti_cards.html", "_contratti_table_rows.html", context, meta)

    window = page_window(total, _safe_page(request), PAGE_SIZE)
    contracts = [] if errors else list(qs[window["start"]:window["end"]])
    context = {
        "contratti": contracts,
        "total_count": total,
        "pager": window,
        "filters": filters,
        "search_errors": errors,
        "query_string": _query_without(request, "page", "export"),
    }
    return render(request, "contratti.html", context)

def lista_telefonate(request):
    qs, errors, filters, active_filters = call_queryset(request.GET)
    stats = StatisticheTelefonate.objects.filter(pk=1).first()
    global_total = int(stats.totaleTelefonate) if stats else None
    row_filters_active = _call_row_filters_active(filters)

    if request.GET.get("export") == "excel" and not errors:
        if not active_filters and global_total is not None:
            export_total = global_total
        elif not row_filters_active:
            export_total = _fast_contract_scoped_call_count(filters)
        else:
            export_total = qs.order_by().count()

        limited = qs[:CALL_EXPORT_LIMIT]
        rows = (
            (
                item.effettuataDa_id,
                item.data.strftime("%d/%m/%Y"),
                item.ora.strftime("%H:%M:%S"),
                item.durata,
                item.effettuataDa.get_tipo_display(),
                item.costo,
            )
            for item in limited.iterator(chunk_size=2000)
        )
        note = None
        if export_total > CALL_EXPORT_LIMIT:
            note = (
                f"Il file contiene i primi {CALL_EXPORT_LIMIT:,} risultati su {export_total:,}. "
                "Applicare filtri più specifici per esportare un insieme completo più ristretto."
            ).replace(",", ".")
        return spreadsheet_response(
            "chiamate.xls",
            "Chiamate",
            ["Numero chiamante", "Data", "Ora", "Durata (secondi)", "Piano", "Addebito (€)"],
            rows,
            ["right", "right", "right", "right", "left", "right"],
            ["text", "date", "text", "integer", "text", "currency"],
            note=note,
        )

    if request.GET.get("count_only") == "1":
        if errors:
            total = 0
        elif not active_filters and global_total is not None:
            total = global_total
        elif not row_filters_active:
            total = _fast_contract_scoped_call_count(filters)
        else:
            # Solo i filtri che riguardano direttamente le righe richiedono un
            # COUNT sulla tabella Telefonata. Il browser lo esegue in parallelo
            # alla lettura del primo blocco, come nel Progetto 1.
            total = qs.order_by().count()
        return JsonResponse({"total_count": int(total)})

    if request.GET.get("ajax_rows") == "1":
        calls, meta = _lazy_queryset_block(qs, request, PAGE_SIZE, allow_reverse=True) if not errors else ([], {
            "start": 0,
            "end": 0,
            "limit": PAGE_SIZE,
            "has_prev": False,
            "has_next": False,
            "next_offset": 0,
            "prev_offset": 0,
            "reverse_offset": 0,
            "from_end": False,
        })
        context = {
            "chiamate": calls,
            "total_count": global_total if not active_filters else None,
            "filters": filters,
            "search_errors": errors,
        }
        if not active_filters and global_total is not None:
            meta["total_count"] = global_total
        return _json_fragments("_telefonate_cards.html", "_telefonate_table_rows.html", context, meta)

    # Nelle ricerche AJAX filtrate non ripetiamo qui il COUNT: ajax.js lo ha
    # già avviato in parallelo. Mostriamo subito il primo blocco e aggiorniamo
    # il totale appena la richiesta count_only termina.
    defer_count = bool(active_filters and _is_xhr(request))
    if errors:
        total = 0
        calls = []
        window = page_window(0, 1, PAGE_SIZE)
    elif defer_count:
        raw = list(qs[: PAGE_SIZE + 1])
        has_next = len(raw) > PAGE_SIZE
        calls = raw[:PAGE_SIZE]
        total = None
        window = {
            "page": 1,
            "pages": 1,
            "start": 0,
            "end": len(calls),
            "display_start": 1 if calls else 0,
            "has_prev": False,
            "has_next": has_next,
            "prev_page": 1,
            "next_page": 1,
            "limit": PAGE_SIZE,
        }
    else:
        if not active_filters and global_total is not None:
            total = global_total
        elif not row_filters_active:
            total = _fast_contract_scoped_call_count(filters)
        else:
            total = qs.order_by().count()
        window = page_window(total, _safe_page(request), PAGE_SIZE)
        calls = list(qs[window["start"]:window["end"]])

    context = {
        "chiamate": calls,
        "total_count": total,
        "count_pending": total is None,
        "pager": window,
        "filters": filters,
        "search_errors": errors,
        "query_string": _query_without(request, "page", "export"),
    }
    return render(request, "telefonate.html", context)

def gestione_sim(request):
    rows, errors, filters = sim_rows(request.GET)
    if request.GET.get("export") == "excel" and not errors:
        export_rows = (
            (
                row["codice"],
                row["state_label"],
                row["numero"],
                row["activation"].strftime("%d/%m/%Y") if row["activation"] else "",
                row["deactivation"].strftime("%d/%m/%Y") if row["deactivation"] else "",
                row["tipoSIM"],
                "A consumo" if row["plan"] == "consumo" else ("Ricaricabile" if row["plan"] == "ricarica" else ""),
                row["calls"],
            )
            for row in rows
        )
        return spreadsheet_response(
            "sim.xls",
            "SIM",
            ["Codice SIM", "Stato", "Numero collegato", "Data attivazione", "Data disattivazione", "Formato SIM", "Piano", "Chiamate"],
            export_rows,
            ["right", "left", "right", "right", "right", "left", "left", "right"],
            ["text", "text", "text", "date", "date", "text", "text", "integer"],
        )

    total = 0 if errors else len(rows)
    selected_states = filters["sim_states"]
    state_title = "Tutte le SIM"
    state_filter_label = "Mostra tutte"
    sim_state_key = "tutte"
    if selected_states == ["attive"]:
        state_title = "SIM in uso"
        state_filter_label = "SIM in uso"
        sim_state_key = "attive"
    elif selected_states == ["disponibili"]:
        state_title = "SIM disponibili"
        state_filter_label = "SIM disponibili"
        sim_state_key = "disponibili"
    elif selected_states == ["disattive"]:
        state_title = "SIM disattivate"
        state_filter_label = "SIM disattivate"
        sim_state_key = "disattive"
    elif len(selected_states) == 2:
        labels = {"attive": "SIM in uso", "disponibili": "SIM disponibili", "disattive": "SIM disattivate"}
        state_filter_label = " + ".join(labels[state] for state in selected_states)

    has_associated_state_filter = "attive" in selected_states or "disattive" in selected_states
    if selected_states == ["attive"]:
        sim_date_filter_label = "Attivata dal/al:"
    elif selected_states == ["disattive"]:
        sim_date_filter_label = "Disattivata dal/al:"
    else:
        sim_date_filter_label = "Attivata/disattivata dal/al:"
    sim_state_order_locked = filters["ordine_sim"] in {"attivate_recenti", "disattivate_recenti"}

    def base_context(page_rows):
        return {
            "sim_rows": page_rows,
            "total_count": total,
            "filters": filters,
            "state_title": state_title,
            "state_filter_label": state_filter_label,
            "sim_state_key": sim_state_key,
            "has_associated_state_filter": has_associated_state_filter,
            "sim_date_filter_label": sim_date_filter_label,
            "sim_state_order_locked": sim_state_order_locked,
            "sim_return_url": reverse("gestione_sim") + ("" if sim_state_key == "tutte" else f"?sim_states={sim_state_key}"),
            "search_errors": errors,
        }

    if request.GET.get("count_only") == "1":
        return JsonResponse({"total_count": total})
    if request.GET.get("ajax_rows") == "1":
        meta = _lazy_window(total, request, PAGE_SIZE)
        page_rows = [] if errors else rows[meta["start"]:meta["end"]]
        context = base_context(page_rows)
        meta["total_count"] = total
        return _json_fragments("_sim_cards.html", "_sim_table_rows.html", context, meta)

    window = page_window(total, _safe_page(request), PAGE_SIZE)
    page_rows = [] if errors else rows[window["start"]:window["end"]]
    context = base_context(page_rows)
    context.update({
        "pager": window,
        "query_string": _query_without(request, "page", "export"),
    })
    return render(request, "sim.html", context)

def _sim_payload(active: SIMAttiva) -> dict:
    latest_call = Telefonata.objects.filter(effettuataDa_id=active.associataA_id).aggregate(latest=Max("data"))["latest"]
    minimum = max(filter(None, [active.dataAttivazione, latest_call]))
    return {
        "exists": True,
        "status": "attiva",
        "hasActiveSim": True,
        "codice": active.codice,
        "numero": active.associataA_id,
        "tipoSIM": active.tipoSIM,
        "dataAttivazione": active.dataAttivazione.isoformat(),
        "ultimaChiamata": latest_call.isoformat() if latest_call else "",
        "dataMinimaDisattivazione": minimum.isoformat(),
        "dataMassimaDisattivazione": timezone.localdate().isoformat(),
        "message": "SIM in uso trovata.",
    }


def sim_lookup(request):
    code = (request.GET.get("codice") or "").strip()
    if not code or not code.isdigit():
        return JsonResponse({"exists": False, "status": "", "message": "Inserire un codice SIM composto soltanto da cifre."})
    active = SIMAttiva.objects.filter(pk=code).select_related("associataA").first()
    if active:
        return JsonResponse(_sim_payload(active))
    if SIMNonAttiva.objects.filter(pk=code).exists():
        return JsonResponse({"exists": True, "status": "disponibile", "message": "La SIM è disponibile e non può essere disattivata perché non risulta in uso."})
    if SIMDisattiva.objects.filter(pk=code).exists():
        return JsonResponse({"exists": True, "status": "disattiva", "message": "La SIM è già presente nello storico delle SIM disattivate."})
    return JsonResponse({"exists": False, "status": "", "message": "Il codice SIM non risulta registrato."})


def numero_lookup(request):
    number = (request.GET.get("numero") or "").strip()
    mode = (request.GET.get("mode") or "edit").strip()
    if not number or not number.isdigit():
        return JsonResponse({"exists": False, "message": "Inserire un numero telefonico composto soltanto da cifre."})
    contract = ContrattoTelefonico.objects.filter(pk=number).first()
    if not contract:
        return JsonResponse({"exists": False, "message": "Il numero indicato non risulta registrato."})
    active = SIMAttiva.objects.filter(associataA=contract).select_related("associataA").first()
    if mode == "create" and not active:
        return JsonResponse({"exists": True, "hasActiveSim": False, "numero": number, "message": "Il numero è registrato, ma non risulta associato a una SIM in uso."})
    if active:
        return JsonResponse(_sim_payload(active))
    return JsonResponse({"exists": True, "hasActiveSim": False, "numero": number, "dataMassimaDisattivazione": timezone.localdate().isoformat()})


def _safe_return_url(request, default: str) -> str:
    candidate = (request.POST.get("return") or request.GET.get("return") or "").strip()
    return candidate if candidate.startswith("/") and not candidate.startswith("//") else default


def _configure_sim_form_dates(form, minimum=None) -> None:
    maximum = timezone.localdate()
    attrs = form.fields["dataDisattivazione"].widget.attrs
    attrs["max"] = maximum.isoformat()
    if minimum:
        attrs["min"] = minimum.isoformat()


def sim_create(request):
    initial = {}
    code = (request.GET.get("codice") or "").strip()
    if code:
        active = SIMAttiva.objects.filter(pk=code).select_related("associataA").first()
        if active:
            initial = {
                "codice": active.codice,
                "tipoSIM": active.tipoSIM,
                "eraAssociataA": active.associataA_id,
                "dataAttivazione": active.dataAttivazione,
            }
    if request.method == "POST":
        form = SIMDisattivaForm(request.POST)
        if form.is_valid():
            cleaned = form.cleaned_data
            active = SIMAttiva.objects.filter(pk=cleaned["codice"]).select_related("associataA").first()
            if not active:
                form.add_error("codice", "La SIM indicata non risulta attualmente in uso.")
            elif active.associataA_id != cleaned["eraAssociataA"]:
                form.add_error("eraAssociataA", "Il numero indicato non corrisponde a quello associato alla SIM.")
            elif active.tipoSIM != cleaned["tipoSIM"]:
                form.add_error("tipoSIM", "Il formato indicato non corrisponde alla SIM registrata.")
            elif active.dataAttivazione != cleaned["dataAttivazione"]:
                form.add_error("dataAttivazione", "La data di attivazione non corrisponde ai dati registrati.")
            else:
                latest_call = Telefonata.objects.filter(effettuataDa_id=active.associataA_id).aggregate(latest=Max("data"))["latest"]
                minimum = max(filter(None, [active.dataAttivazione, latest_call]))
                end = cleaned["dataDisattivazione"]
                if end < minimum:
                    form.add_error("dataDisattivazione", f"La disattivazione non può precedere il {minimum.strftime('%d/%m/%Y')}.")
                elif end > timezone.localdate():
                    form.add_error("dataDisattivazione", "La disattivazione non può essere successiva alla data odierna.")
                else:
                    call_count = Telefonata.objects.filter(effettuataDa_id=active.associataA_id, data__gte=active.dataAttivazione, data__lte=end).count()
                    with transaction.atomic():
                        SIMDisattiva.objects.create(
                            codice=active.codice,
                            tipoSIM=active.tipoSIM,
                            eraAssociataA=active.associataA,
                            dataAttivazione=active.dataAttivazione,
                            dataDisattivazione=end,
                        )
                        active.delete()
                        StatisticheSIM.objects.filter(codice=cleaned["codice"]).delete()
                        StatisticheSIM.objects.create(codice=cleaned["codice"], stato="disattive", numeroChiamate=call_count)
                    messages.success(request, "SIM disattivata registrata nello storico correttamente.")
                    return redirect(_safe_return_url(request, f"{reverse('gestione_sim')}?sim_states=disattive&codice={cleaned['codice']}"))
    else:
        form = SIMDisattivaForm(initial=initial)

    minimum = None
    lookup_code = (form.data.get("codice") if form.is_bound else initial.get("codice")) or ""
    if str(lookup_code).isdigit():
        active_for_dates = SIMAttiva.objects.filter(pk=str(lookup_code)).first()
        if active_for_dates:
            latest_call = Telefonata.objects.filter(effettuataDa_id=active_for_dates.associataA_id).aggregate(latest=Max("data"))["latest"]
            minimum = max(filter(None, [active_for_dates.dataAttivazione, latest_call]))
    _configure_sim_form_dates(form, minimum)
    return render(request, "sim_form.html", {
        "form": form,
        "mode": "create",
        "return_url": _safe_return_url(request, reverse("gestione_sim")),
    })


def sim_edit(request, codice: str):
    item = get_object_or_404(SIMDisattiva.objects.select_related("eraAssociataA"), pk=codice)
    initial = {
        "codice": item.codice,
        "tipoSIM": item.tipoSIM,
        "eraAssociataA": item.eraAssociataA_id,
        "dataAttivazione": item.dataAttivazione,
        "dataDisattivazione": item.dataDisattivazione,
    }
    if request.method == "POST":
        form = SIMDisattivaForm(request.POST)
        if form.is_valid():
            cleaned = form.cleaned_data
            if cleaned["codice"] != item.codice:
                form.add_error("codice", "Il codice SIM dello storico non può essere modificato.")
            contract = ContrattoTelefonico.objects.filter(pk=cleaned["eraAssociataA"]).first()
            if not contract:
                form.add_error("eraAssociataA", "Il numero indicato non risulta registrato.")
            elif cleaned["dataDisattivazione"] > timezone.localdate():
                form.add_error("dataDisattivazione", "La disattivazione non può essere successiva alla data odierna.")
            if form.is_valid() and contract:
                item.tipoSIM = cleaned["tipoSIM"]
                item.eraAssociataA = contract
                item.dataDisattivazione = cleaned["dataDisattivazione"]
                item.full_clean()
                item.save()
                call_count = Telefonata.objects.filter(effettuataDa=contract, data__gte=item.dataAttivazione, data__lte=item.dataDisattivazione).count()
                StatisticheSIM.objects.update_or_create(codice=item.codice, stato="disattive", defaults={"numeroChiamate": call_count})
                messages.success(request, "Dati della SIM disattivata aggiornati correttamente.")
                return redirect(_safe_return_url(request, f"{reverse('gestione_sim')}?sim_states=disattive&codice={item.codice}"))
    else:
        form = SIMDisattivaForm(initial=initial)
    code_attrs = form.fields["codice"].widget.attrs
    code_attrs["readonly"] = "readonly"
    code_attrs["class"] = "input-readonly"
    code_attrs.pop("data-sim-code-lookup", None)
    form.fields["dataAttivazione"].widget.attrs["readonly"] = "readonly"
    _configure_sim_form_dates(form, item.dataAttivazione)
    return render(request, "sim_form.html", {
        "form": form,
        "mode": "edit",
        "sim": item,
        "return_url": _safe_return_url(request, f"{reverse('gestione_sim')}?sim_states=disattive&codice={item.codice}"),
    })


def sim_delete(request, codice: str):
    item = get_object_or_404(SIMDisattiva.objects.select_related("eraAssociataA"), pk=codice)
    return_url = _safe_return_url(request, f"{reverse('gestione_sim')}?sim_states=disattive")
    if request.method == "POST":
        with transaction.atomic():
            StatisticheSIM.objects.filter(codice=item.codice, stato="disattive").delete()
            item.delete()
        messages.success(request, "SIM disattivata rimossa dallo storico correttamente.")
        return redirect(return_url)
    return render(request, "sim_confirm_delete.html", {"sim": item, "return_url": return_url})
