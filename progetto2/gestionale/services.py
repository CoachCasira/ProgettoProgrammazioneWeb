from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime, timedelta
from decimal import Decimal, InvalidOperation
from math import ceil
from typing import Any

from django.db.models import (
    BigIntegerField,
    BooleanField,
    Case,
    Count,
    DateField,
    DecimalField,
    Exists,
    F,
    IntegerField,
    OuterRef,
    Q,
    Subquery,
    Value,
    When,
)
from django.db.models.functions import Coalesce

from .models import (
    ContrattoTelefonico,
    SIMAttiva,
    SIMDisattiva,
    SIMNonAttiva,
    StatisticheSIM,
    Telefonata,
)

PHONE_DURATION_PRESETS = {
    "30m": 30 * 60,
    "1h": 60 * 60,
    "5h": 5 * 60 * 60,
    "24h": 24 * 60 * 60,
}
CALL_DURATION_PRESETS = {
    "30s": 30,
    "1m": 60,
    "5m": 5 * 60,
    "30m": 30 * 60,
    "1h": 60 * 60,
}


def clean_digits(value: str, label: str, errors: list[str]) -> str:
    value = (value or "").strip()
    if value and not value.isdigit():
        errors.append(f"{label} può contenere esclusivamente cifre.")
        return ""
    return value


def clean_date(value: str, label: str, errors: list[str]) -> date | None:
    value = (value or "").strip()
    if not value:
        return None
    try:
        return datetime.strptime(value, "%Y-%m-%d").date()
    except ValueError:
        errors.append(f"{label} non è valida.")
        return None


def clean_time(value: str, label: str, errors: list[str]):
    value = (value or "").strip()
    if not value:
        return None
    try:
        return datetime.strptime(value, "%H:%M").time()
    except ValueError:
        errors.append(f"{label} non è valido.")
        return None


def clean_nonnegative_int(value: str, label: str, errors: list[str]) -> int | None:
    value = (value or "").strip()
    if value == "":
        return None
    if not value.isdigit():
        errors.append(f"{label} deve essere un numero intero non negativo.")
        return None
    return int(value)


def clean_decimal(value: str, label: str, errors: list[str]) -> Decimal | None:
    value = (value or "").strip().replace(",", ".")
    if value == "":
        return None
    try:
        number = Decimal(value)
    except InvalidOperation:
        errors.append(f"{label} deve essere un importo valido.")
        return None
    if number < 0:
        errors.append(f"{label} non può essere negativo.")
        return None
    return number


def duration_threshold(params, presets: dict[str, int], errors: list[str]) -> tuple[int, dict[str, str]]:
    preset = (params.get("durata_preset") or "").strip()
    raw = {
        "durata_preset": preset,
        "durata_ore": (params.get("durata_ore") or "").strip(),
        "durata_min": (params.get("durata_min") or "").strip(),
        "durata_sec": (params.get("durata_sec") or "").strip(),
    }
    if not preset:
        return 0, raw
    if preset in presets:
        return presets[preset], raw
    if preset != "custom":
        errors.append("La durata selezionata non è valida.")
        return 0, raw
    hours = clean_nonnegative_int(raw["durata_ore"], "Le ore", errors) or 0
    minutes = clean_nonnegative_int(raw["durata_min"], "I minuti", errors) or 0
    seconds = clean_nonnegative_int(raw["durata_sec"], "I secondi", errors) or 0
    if minutes > 59:
        errors.append("I minuti della durata personalizzata devono essere compresi tra 0 e 59.")
    if seconds > 59:
        errors.append("I secondi della durata personalizzata devono essere compresi tra 0 e 59.")
    return hours * 3600 + minutes * 60 + seconds, raw


def contract_queryset(params):
    errors: list[str] = []
    number = clean_digits(params.get("numero", ""), "Il numero di telefono", errors)
    plan = (params.get("tipo") or "").strip()
    state = (params.get("stato_numero") or "").strip()
    residual = (params.get("residuo") or "").strip()
    order = (params.get("ordine") or "recenti").strip()
    data_from = clean_date(params.get("data_da", ""), "La data iniziale", errors)
    data_to = clean_date(params.get("data_a", ""), "La data finale", errors)
    if data_from and data_to and data_from > data_to:
        errors.append("La data iniziale non può essere successiva alla data finale.")

    min_calls_choice = (params.get("min_chiamate") or "").strip()
    min_calls = 0
    if min_calls_choice in {"1", "50", "100"}:
        min_calls = int(min_calls_choice)
    elif min_calls_choice == "custom":
        min_calls = clean_nonnegative_int(params.get("min_chiamate_custom", ""), "La soglia delle chiamate", errors) or 0
    elif min_calls_choice:
        errors.append("La soglia delle chiamate selezionata non è valida.")

    min_duration, duration_values = duration_threshold(params, PHONE_DURATION_PRESETS, errors)
    if plan not in {"", "consumo", "ricarica"}:
        errors.append("Il piano selezionato non è valido.")
    if state not in {"", "attivo", "disattivato"}:
        errors.append("Lo stato del numero selezionato non è valido.")
    if residual not in {"", "esaurito", "credito_basso", "minuti_bassi", "credito_disponibile", "minuti_disponibili"}:
        errors.append("La disponibilità del piano selezionata non è valida.")
    allowed_orders = {"recenti", "disattivati_recenti", "chiamate_crescenti", "piu_chiamate", "maggiore_durata", "maggiore_spesa"}
    if order not in allowed_orders:
        errors.append("Il criterio di ordinamento selezionato non è valido.")
        order = "recenti"

    latest_disabled = SIMDisattiva.objects.filter(eraAssociataA=OuterRef("pk")).order_by("-dataDisattivazione", "codice")
    active_exists = SIMAttiva.objects.filter(associataA=OuterRef("pk"))
    qs = ContrattoTelefonico.objects.annotate(
        num_calls=Coalesce(F("statistiche__numeroTelefonate"), Value(0), output_field=BigIntegerField()),
        total_duration=Coalesce(F("statistiche__durataTotale"), Value(0), output_field=BigIntegerField()),
        total_cost=Coalesce(F("statistiche__addebitoTotale"), Value(Decimal("0")), output_field=DecimalField(max_digits=18, decimal_places=2)),
        active_sim_code=Subquery(SIMAttiva.objects.filter(associataA=OuterRef("pk")).values("codice")[:1]),
        latest_disabled_code=Subquery(latest_disabled.values("codice")[:1]),
        latest_disabled_date=Subquery(latest_disabled.values("dataDisattivazione")[:1], output_field=DateField()),
        disabled_count=Count("sim_disattive", distinct=True),
        is_active=Exists(active_exists),
    ).filter(Q(is_active=True) | Q(disabled_count__gt=0))

    if number:
        qs = qs.filter(numero=number) if len(number) >= 10 else qs.filter(numero__contains=number)
    if plan:
        qs = qs.filter(tipo=plan)
    if state == "attivo":
        qs = qs.filter(is_active=True)
    elif state == "disattivato":
        qs = qs.filter(is_active=False, disabled_count__gt=0)
    if residual == "esaurito":
        qs = qs.filter(Q(tipo="ricarica", creditoResiduo=0) | Q(tipo="consumo", minutiResidui=0))
    elif residual == "credito_basso":
        qs = qs.filter(tipo="ricarica", creditoResiduo__gt=0, creditoResiduo__lt=5)
    elif residual == "minuti_bassi":
        qs = qs.filter(tipo="consumo", minutiResidui__gt=0, minutiResidui__lt=30)
    elif residual == "credito_disponibile":
        qs = qs.filter(tipo="ricarica", creditoResiduo__gte=5)
    elif residual == "minuti_disponibili":
        qs = qs.filter(tipo="consumo", minutiResidui__gte=30)
    if min_calls:
        qs = qs.filter(num_calls__gte=min_calls)
    if min_duration:
        qs = qs.filter(total_duration__gte=min_duration)
    if data_from:
        qs = qs.filter(latest_disabled_date__gte=data_from) if state == "disattivato" else qs.filter(dataAttivazione__gte=data_from)
    if data_to:
        qs = qs.filter(latest_disabled_date__lte=data_to) if state == "disattivato" else qs.filter(dataAttivazione__lte=data_to)

    if order == "disattivati_recenti":
        qs = qs.order_by("is_active", F("latest_disabled_date").desc(nulls_last=True), "-dataAttivazione", "numero")
    elif order == "chiamate_crescenti":
        qs = qs.order_by("num_calls", "-dataAttivazione", "numero")
    elif order == "piu_chiamate":
        qs = qs.order_by("-num_calls", "-dataAttivazione", "numero")
    elif order == "maggiore_durata":
        qs = qs.order_by("-total_duration", "-num_calls", "numero")
    elif order == "maggiore_spesa":
        qs = qs.order_by("-total_cost", "-num_calls", "numero")
    elif min_calls:
        qs = qs.order_by("num_calls", "-dataAttivazione", "numero")
    elif residual in {"credito_basso", "credito_disponibile"}:
        qs = qs.order_by("creditoResiduo", "-dataAttivazione", "numero")
    elif residual in {"minuti_bassi", "minuti_disponibili"}:
        qs = qs.order_by("minutiResidui", "-dataAttivazione", "numero")
    elif not state:
        qs = qs.order_by("-is_active", "-dataAttivazione", "numero")
    else:
        qs = qs.order_by("-dataAttivazione", "numero")

    values = {
        "numero": number,
        "tipo": plan,
        "stato_numero": state,
        "residuo": residual,
        "min_chiamate": min_calls_choice,
        "min_chiamate_custom": (params.get("min_chiamate_custom") or "").strip(),
        "ordine": order,
        "data_da": params.get("data_da", ""),
        "data_a": params.get("data_a", ""),
        **duration_values,
    }
    return qs, errors, values


def call_queryset(params):
    errors: list[str] = []
    number = clean_digits(params.get("contratto", ""), "Il numero chiamante", errors)
    state = (params.get("stato_numero") or "").strip()
    plan = (params.get("piano") or "").strip()
    order = (params.get("ordine") or "recenti").strip()
    date_from = clean_date(params.get("data_da", ""), "La data iniziale", errors)
    date_to = clean_date(params.get("data_a", ""), "La data finale", errors)
    time_from = clean_time(params.get("ora_da", ""), "L’ora iniziale", errors)
    time_to = clean_time(params.get("ora_a", ""), "L’ora finale", errors)
    max_cost = clean_decimal(params.get("costo_max", ""), "L’addebito massimo", errors)
    min_duration, duration_values = duration_threshold(params, CALL_DURATION_PRESETS, errors)

    if date_from and date_to and date_from > date_to:
        errors.append("La data iniziale non può essere successiva alla data finale.")
    if time_from and time_to and time_from > time_to:
        errors.append("L’ora iniziale non può essere successiva all’ora finale.")
    if state not in {"", "attivo", "disattivato"}:
        errors.append("Lo stato del numero selezionato non è valido.")
    if plan not in {"", "consumo", "ricarica"}:
        errors.append("Il piano selezionato non è valido.")
    allowed_orders = {"recenti", "meno_recenti", "durata_desc", "durata_asc", "costo_desc", "costo_asc"}
    if order not in allowed_orders:
        errors.append("Il criterio di ordinamento selezionato non è valido.")
        order = "recenti"

    # Stato e piano appartengono al contratto, che contiene poche centinaia di
    # righe. Filtriamo prima i numeri compatibili e poi usiamo l'indice di
    # Telefonata.effettuataDa, evitando un EXISTS correlato su milioni di righe.
    contract_scope = ContrattoTelefonico.objects.all()
    if state:
        contract_scope = contract_scope.annotate(
            has_active_sim=Exists(SIMAttiva.objects.filter(associataA=OuterRef("pk")))
        )
        if state == "attivo":
            contract_scope = contract_scope.filter(has_active_sim=True)
        else:
            contract_scope = contract_scope.filter(
                has_active_sim=False, sim_disattive__isnull=False
            ).distinct()
    if plan:
        contract_scope = contract_scope.filter(tipo=plan)

    qs = Telefonata.objects.select_related("effettuataDa")
    if number:
        # Il Progetto 1 interpreta un numero parziale come prefisso.
        qs = qs.filter(effettuataDa_id=number) if len(number) >= 10 else qs.filter(effettuataDa_id__startswith=number)
    if state or plan:
        qs = qs.filter(effettuataDa_id__in=Subquery(contract_scope.values("numero")))
    if date_from:
        qs = qs.filter(data__gte=date_from)
    if date_to:
        qs = qs.filter(data__lte=date_to)
    if time_from:
        qs = qs.filter(ora__gte=time_from)
    if time_to:
        qs = qs.filter(ora__lte=time_to)
    if min_duration:
        qs = qs.filter(durata__gte=min_duration)
    if max_cost is not None:
        qs = qs.filter(costo__lte=max_cost)

    # Gli ordinamenti seguono il Progetto 1. Per durata e costo la chiave
    # primaria chiude l'ordinamento e permette a PostgreSQL di sfruttare gli
    # indici senza ordinare inutilmente milioni di righe per data e ora.
    if order == "recenti" and min_duration and (time_from or time_to):
        ordering = ("durata", "ora", "-data", "-id")
    elif order == "recenti" and min_duration:
        ordering = ("durata", "-data", "-ora", "-id")
    elif order == "recenti" and (time_from or time_to):
        ordering = ("ora", "-data", "-id")
    else:
        ordering = {
            "recenti": ("-data", "-ora", "-id"),
            "meno_recenti": ("data", "ora", "id"),
            "durata_desc": ("-durata", "-id"),
            "durata_asc": ("durata", "id"),
            "costo_desc": ("-costo", "-id"),
            "costo_asc": ("costo", "id"),
        }[order]
    qs = qs.order_by(*ordering)

    values = {
        "contratto": number,
        "stato_numero": state,
        "piano": plan,
        "ordine": order,
        "data_da": params.get("data_da", ""),
        "data_a": params.get("data_a", ""),
        "ora_da": params.get("ora_da", ""),
        "ora_a": params.get("ora_a", ""),
        "costo_max": params.get("costo_max", ""),
        **duration_values,
    }
    active_filters = any(str(values.get(key, "")).strip() for key in values if key != "ordine")
    return qs, errors, values, active_filters

def sim_rows(params):
    errors: list[str] = []
    code = clean_digits(params.get("codice", ""), "Il codice SIM", errors)
    number = clean_digits(params.get("numero", ""), "Il numero collegato", errors)
    sim_type = (params.get("tipoSIM") or "").strip()
    plan = (params.get("piano") or "").strip()
    order = (params.get("ordine_sim") or "nessuno").strip()
    selected_states = []
    if hasattr(params, "getlist"):
        selected_states = params.getlist("sim_states[]") or params.getlist("sim_states")
    if not selected_states:
        legacy = (params.get("stato") or "tutte").strip()
        selected_states = [legacy] if legacy in {"attive", "disponibili", "disattive"} else ["attive", "disponibili", "disattive"]
    selected_states = [state for state in selected_states if state in {"attive", "disponibili", "disattive"}]
    if not selected_states:
        selected_states = ["attive", "disponibili", "disattive"]
    date_from = clean_date(params.get("data_da", ""), "La data iniziale", errors)
    date_to = clean_date(params.get("data_a", ""), "La data finale", errors)
    if date_from and date_to and date_from > date_to:
        errors.append("La data iniziale non può essere successiva alla data finale.")
    if sim_type not in {"", "Nano", "Micro", "Standard", "eSIM"}:
        errors.append("Il formato SIM selezionato non è valido.")
    if plan not in {"", "consumo", "ricarica"}:
        errors.append("Il piano selezionato non è valido.")
    if order not in {"nessuno", "piu_chiamate", "attivate_recenti", "disattivate_recenti"}:
        errors.append("Il criterio di ordinamento selezionato non è valido.")
        order = "nessuno"

    # Gli ordinamenti basati sulle date determinano anche lo stato coerente,
    # come nel Progetto 1: non ha senso ordinare SIM disponibili per una data
    # di attivazione o disattivazione che non possiedono.
    if order == "attivate_recenti":
        selected_states = ["attive"]
    elif order == "disattivate_recenti":
        selected_states = ["disattive"]

    has_associated_state = "attive" in selected_states or "disattive" in selected_states
    if not has_associated_state:
        number = ""
        plan = ""
        date_from = None
        date_to = None
        order = "nessuno"

    stats = {(item.codice, item.stato): item.numeroChiamate for item in StatisticheSIM.objects.all()}
    rows: list[dict[str, Any]] = []
    if "attive" in selected_states:
        qs = SIMAttiva.objects.select_related("associataA")
        if code:
            qs = qs.filter(codice__contains=code)
        if number:
            qs = qs.filter(associataA__numero__contains=number)
        if sim_type:
            qs = qs.filter(tipoSIM=sim_type)
        if plan:
            qs = qs.filter(associataA__tipo=plan)
        if date_from:
            qs = qs.filter(dataAttivazione__gte=date_from)
        if date_to:
            qs = qs.filter(dataAttivazione__lte=date_to)
        for item in qs:
            rows.append({
                "codice": item.codice,
                "tipoSIM": item.tipoSIM,
                "state": "attive",
                "state_label": "In uso",
                "numero": item.associataA_id,
                "plan": item.associataA.tipo,
                "activation": item.dataAttivazione,
                "deactivation": None,
                "calls": stats.get((item.codice, "attive"), 0),
            })
    if "disponibili" in selected_states and not number and not plan and not date_from and not date_to:
        qs = SIMNonAttiva.objects.all()
        if code:
            qs = qs.filter(codice__contains=code)
        if sim_type:
            qs = qs.filter(tipoSIM=sim_type)
        for item in qs:
            rows.append({
                "codice": item.codice,
                "tipoSIM": item.tipoSIM,
                "state": "disponibili",
                "state_label": "Disponibile",
                "numero": "",
                "plan": "",
                "activation": None,
                "deactivation": None,
                "calls": stats.get((item.codice, "disponibili"), 0),
            })
    if "disattive" in selected_states:
        qs = SIMDisattiva.objects.select_related("eraAssociataA")
        if code:
            qs = qs.filter(codice__contains=code)
        if number:
            qs = qs.filter(eraAssociataA__numero__contains=number)
        if sim_type:
            qs = qs.filter(tipoSIM=sim_type)
        if plan:
            qs = qs.filter(eraAssociataA__tipo=plan)
        if date_from:
            qs = qs.filter(dataDisattivazione__gte=date_from)
        if date_to:
            qs = qs.filter(dataDisattivazione__lte=date_to)
        for item in qs:
            rows.append({
                "codice": item.codice,
                "tipoSIM": item.tipoSIM,
                "state": "disattive",
                "state_label": "Disattivata",
                "numero": item.eraAssociataA_id,
                "plan": item.eraAssociataA.tipo,
                "activation": item.dataAttivazione,
                "deactivation": item.dataDisattivazione,
                "calls": stats.get((item.codice, "disattive"), 0),
            })

    if order == "piu_chiamate":
        rows.sort(key=lambda row: (-row["calls"], row["codice"]))
    elif order == "attivate_recenti":
        rows.sort(key=lambda row: (row["activation"] is None, -(row["activation"].toordinal() if row["activation"] else 0), row["codice"]))
    elif order == "disattivate_recenti":
        rows.sort(key=lambda row: (row["deactivation"] is None, -(row["deactivation"].toordinal() if row["deactivation"] else 0), row["codice"]))
    else:
        # Ordinamento predefinito identico al Progetto 1: prima le SIM
        # disponibili, poi quelle in uso e infine lo storico delle disattivate.
        # Nei gruppi con una data operativa vengono mostrate prima le più recenti.
        state_order = {"disponibili": 0, "attive": 1, "disattive": 2}

        def default_sim_order(row):
            reference_date = row["deactivation"] or row["activation"]
            date_order = -(reference_date.toordinal()) if reference_date else 0
            return state_order[row["state"]], date_order, row["codice"]

        rows.sort(key=default_sim_order)

    values = {
        "codice": code,
        "numero": number,
        "tipoSIM": sim_type,
        "piano": plan,
        "ordine_sim": order,
        "sim_states": selected_states,
        "data_da": params.get("data_da", ""),
        "data_a": params.get("data_a", ""),
    }
    return rows, errors, values


def page_window(total: int, page: int, page_size: int = 12) -> dict[str, int | bool]:
    pages = max(1, ceil(total / page_size))
    page = min(max(1, page), pages)
    start = (page - 1) * page_size
    end = min(total, start + page_size)
    return {
        "page": page,
        "pages": pages,
        "start": start,
        "end": end,
        "display_start": start + 1 if total else 0,
        "has_prev": page > 1,
        "has_next": page < pages,
        "prev_page": max(1, page - 1),
        "next_page": min(pages, page + 1),
        "limit": page_size,
    }
