from __future__ import annotations

from datetime import timedelta
from decimal import Decimal
from itertools import chain

from django.contrib import messages
from django.db import transaction
from django.db.models import Max, Q
from django.http import Http404, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
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
            phone_qs = ContrattoTelefonico.objects.filter(numero__contains=query).select_related("statistiche")[:6]
            active_numbers = set(SIMAttiva.objects.filter(associataA_id__in=[item.numero for item in phone_qs]).values_list("associataA_id", flat=True))
            for item in phone_qs:
                phone_results.append({
                    "numero": item.numero,
                    "tipo": item.get_tipo_display(),
                    "data": item.dataAttivazione,
                    "active": item.numero in active_numbers,
                })
            for item in SIMAttiva.objects.filter(codice__contains=query).select_related("associataA")[:6]:
                sim_results.append({"codice": item.codice, "state": "attive", "state_label": "In uso", "tipo": item.get_tipoSIM_display(), "numero": item.associataA_id})
            remaining = max(0, 6 - len(sim_results))
            if remaining:
                for item in SIMNonAttiva.objects.filter(codice__contains=query)[:remaining]:
                    sim_results.append({"codice": item.codice, "state": "disponibili", "state_label": "Disponibile", "tipo": item.get_tipoSIM_display(), "numero": ""})
            remaining = max(0, 6 - len(sim_results))
            if remaining:
                for item in SIMDisattiva.objects.filter(codice__contains=query).select_related("eraAssociataA")[:remaining]:
                    sim_results.append({"codice": item.codice, "state": "disattive", "state_label": "Disattivata", "tipo": item.get_tipoSIM_display(), "numero": item.eraAssociataA_id})

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
    if request.GET.get("export") == "excel" and not errors:
        total = qs.count() if active_filters or not stats else stats.totaleTelefonate
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
        if total > CALL_EXPORT_LIMIT:
            note = f"Il file contiene i primi {CALL_EXPORT_LIMIT:,} risultati su {total:,}. Applicare filtri più specifici per esportare un insieme completo più ristretto.".replace(",", ".")
        return spreadsheet_response(
            "chiamate.xls",
            "Chiamate",
            ["Numero chiamante", "Data", "Ora", "Durata (secondi)", "Piano", "Addebito (€)"],
            rows,
            ["right", "right", "right", "right", "left", "right"],
            ["text", "date", "text", "integer", "text", "currency"],
            note=note,
        )

    if errors:
        total = 0
    elif active_filters or not stats:
        total = qs.count()
    else:
        total = stats.totaleTelefonate
    window = page_window(total, _safe_page(request), PAGE_SIZE)
    calls = [] if errors else list(qs[window["start"]:window["end"]])
    context = {
        "chiamate": calls,
        "total_count": total,
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
    window = page_window(total, _safe_page(request), PAGE_SIZE)
    page_rows = [] if errors else rows[window["start"]:window["end"]]
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
        labels = {
            "attive": "SIM in uso",
            "disponibili": "SIM disponibili",
            "disattive": "SIM disattivate",
        }
        state_filter_label = " + ".join(labels[state] for state in selected_states)

    has_associated_state_filter = "attive" in selected_states or "disattive" in selected_states
    if selected_states == ["attive"]:
        sim_date_filter_label = "Attivata dal/al:"
    elif selected_states == ["disattive"]:
        sim_date_filter_label = "Disattivata dal/al:"
    else:
        sim_date_filter_label = "Attivata/disattivata dal/al:"
    sim_state_order_locked = filters["ordine_sim"] in {"attivate_recenti", "disattivate_recenti"}

    context = {
        "sim_rows": page_rows,
        "total_count": total,
        "pager": window,
        "filters": filters,
        "state_title": state_title,
        "state_filter_label": state_filter_label,
        "sim_state_key": sim_state_key,
        "has_associated_state_filter": has_associated_state_filter,
        "sim_date_filter_label": sim_date_filter_label,
        "sim_state_order_locked": sim_state_order_locked,
        "search_errors": errors,
        "query_string": _query_without(request, "page", "export"),
    }
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
                    return redirect(f"{reverse('gestione_sim')}?sim_states=disattive&codice={cleaned['codice']}")
    else:
        form = SIMDisattivaForm(initial=initial)
    return render(request, "sim_form.html", {"form": form, "mode": "create", "return_url": request.GET.get("return", reverse("gestione_sim"))})


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
                return redirect(f"{reverse('gestione_sim')}?sim_states=disattive&codice={item.codice}")
    else:
        form = SIMDisattivaForm(initial=initial)
    form.fields["codice"].widget.attrs["readonly"] = "readonly"
    form.fields["dataAttivazione"].widget.attrs["readonly"] = "readonly"
    return render(request, "sim_form.html", {"form": form, "mode": "edit", "sim": item, "return_url": reverse("gestione_sim")})


def sim_delete(request, codice: str):
    item = get_object_or_404(SIMDisattiva.objects.select_related("eraAssociataA"), pk=codice)
    if request.method == "POST":
        with transaction.atomic():
            StatisticheSIM.objects.filter(codice=item.codice, stato="disattive").delete()
            item.delete()
        messages.success(request, "SIM disattivata rimossa dallo storico correttamente.")
        return redirect(f"{reverse('gestione_sim')}?sim_states=disattive")
    return render(request, "sim_confirm_delete.html", {"sim": item})
