"""Viste iniziali dell'applicazione.

Questa fase mantiene le funzionalità prototipali già presenti. Filtri,
paginazione completa e CRUD verranno migrati in blocchi successivi.
"""

from django.db.models import Avg, Sum
from django.shortcuts import render

from .models import (
    ContrattoTelefonico,
    SIMAttiva,
    SIMDisattiva,
    SIMNonAttiva,
    StatisticheTelefonate,
    Telefonata,
)


def home(request):
    total_contratti = ContrattoTelefonico.objects.count()
    total_sim_attive = SIMAttiva.objects.count()
    total_sim_non_attive = SIMNonAttiva.objects.count()
    total_sim_disattive = SIMDisattiva.objects.count()
    total_sim_gestite = total_sim_attive + total_sim_non_attive + total_sim_disattive

    contratti_ricarica = ContrattoTelefonico.objects.filter(tipo="ricarica").count()
    contratti_consumo = ContrattoTelefonico.objects.filter(tipo="consumo").count()

    statistiche = StatisticheTelefonate.objects.filter(pk=1).first()
    if statistiche is not None:
        total_telefonate = statistiche.totaleTelefonate
        costo_totale = statistiche.addebitoTotale
        durata_media_secondi = statistiche.durataMedia
    else:
        # Fallback utile durante lo sviluppo prima del caricamento delle statistiche.
        total_telefonate = Telefonata.objects.count()
        aggregati = Telefonata.objects.aggregate(
            costo_totale=Sum("costo"), durata_media=Avg("durata")
        )
        costo_totale = aggregati["costo_totale"] or 0
        durata_media_secondi = aggregati["durata_media"] or 0

    durata_media_intera = int(durata_media_secondi)
    minuti, secondi = divmod(durata_media_intera, 60)
    durata_media = f"{minuti} min {secondi} sec" if minuti else f"{secondi} sec"

    context = {
        "total_contratti": total_contratti,
        "total_telefonate": total_telefonate,
        "total_sim_attive": total_sim_attive,
        "total_sim_non_attive": total_sim_non_attive,
        "total_sim_disattive": total_sim_disattive,
        "total_sim_gestite": total_sim_gestite,
        "contratti_ricarica": contratti_ricarica,
        "contratti_consumo": contratti_consumo,
        "costo_totale": f"{costo_totale:.2f}",
        "durata_media": durata_media,
    }
    return render(request, "index.html", context)


def lista_contratti(request):
    contratti = ContrattoTelefonico.objects.all()
    filtro_numero = request.GET.get("numero", "").strip()
    filtro_tipo = request.GET.get("tipo", "").strip()

    if filtro_numero:
        contratti = contratti.filter(numero__icontains=filtro_numero)
    if filtro_tipo:
        contratti = contratti.filter(tipo=filtro_tipo)

    return render(request, "contratti.html", {"contratti": contratti})


def lista_telefonate(request):
    chiamate = Telefonata.objects.select_related("effettuataDa").all()
    filtro_contratto = request.GET.get("contratto", "").strip()

    if filtro_contratto:
        chiamate = chiamate.filter(
            effettuataDa__numero__icontains=filtro_contratto
        )

    return render(request, "telefonate.html", {"chiamate": chiamate})


def gestione_sim(request):
    stato = request.GET.get("stato", "attive")

    if stato == "disponibili":
        sim_list = SIMNonAttiva.objects.all()
    elif stato == "disattive":
        sim_list = SIMDisattiva.objects.select_related("eraAssociataA").all()
    else:
        stato = "attive"
        sim_list = SIMAttiva.objects.select_related("associataA").all()

    return render(request, "sim.html", {"sim_list": sim_list, "stato": stato})
