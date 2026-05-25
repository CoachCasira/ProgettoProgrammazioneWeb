from django.shortcuts import render
from django.db.models import Sum, Avg
from .models import ContrattoTelefonico, Telefonata, SIMAttiva, SIMNonAttiva, SIMDisattiva

def home(request):
    # 1. Contiamo i record totali di ogni tabella proprio come faceva il vecchio index.php
    total_contratti = ContrattoTelefonico.objects.count()
    total_telefonate = Telefonata.objects.count()
    total_sim_attive = SIMAttiva.objects.count()
    total_sim_non_attive = SIMNonAttiva.objects.count()
    total_sim_disattive = SIMDisattiva.objects.count()
    
    # 2. Calcoliamo il totale delle SIM gestite complessivamente
    total_sim_gestite = total_sim_attive + total_sim_non_attive + total_sim_disattive
    
    # 3. Contiamo quanti contratti sono ricaricabili e quanti a consumo
    contratti_ricarica = ContrattoTelefonico.objects.filter(tipo='ricarica').count()
    contratti_consumo = ContrattoTelefonico.objects.filter(tipo='consumo').count()
    
    # 4. Calcoliamo la somma dei costi e la durata media delle chiamate
    stats_chiamate = Telefonata.objects.aggregate(
        costo_totale=Sum('costo'),
        durata_media=Avg('durata')
    )
    
    costo_totale = stats_chiamate['costo_totale'] or 0.00
    durata_media_secondi = stats_chiamate['durata_media'] or 0
    
    # Formattiamo la durata media in modo che sia leggibile (es. 2m 15s invece di soli secondi)
    if durata_media_secondi > 0:
        minuti = int(durata_media_secondi // 60)
        secondi = int(durata_media_secondi % 60)
        durata_media = f"{minuti}m {secondi}s" if minuti > 0 else f"{secondi}s"
    else:
        durata_media = "0s"

    # 5. Impacchettiamo tutti i dati in un dizionario (il Context)
    context = {
        'total_contratti': total_contratti,
        'total_telefonate': total_telefonate,
        'total_sim_attive': total_sim_attive,
        'total_sim_non_attive': total_sim_non_attive,
        'total_sim_disattive': total_sim_disattive,
        'total_sim_gestite': total_sim_gestite,
        'contratti_ricarica': contratti_ricarica,
        'contratti_consumo': contratti_consumo,
        'costo_totale': f"{costo_totale:.2f}",
        'durata_media': durata_media,
    }
    
    # 6. Spediamo tutto alla pagina HTML
    return render(request, 'index.html', context)

def lista_contratti(request):
    # Prendiamo tutti i contratti dal database
    contratti = ContrattoTelefonico.objects.all()
    
    # Recuperiamo i filtri scritti dall'utente nel form HTML
    filtro_numero = request.GET.get('numero', '').strip()
    filtro_tipo = request.GET.get('tipo', '').strip()
    
    # Se l'utente ha cercato un numero, filtriamo (ricerca parziale: contiene le cifre)
    if filtro_numero:
        contratti = contratti.filter(numero__icontains=filtro_numero)
        
    # Se l'utente ha scelto un piano specifico, filtriamo per tipo
    if filtro_tipo:
        contratti = contratti.filter(tipo=filtro_tipo)
        
    context = {
        'contratti': contratti
    }
    return render(request, 'contratti.html', context)

def lista_telefonate(request):
    # Prendiamo tutte le telefonate dal database e colleghiamo i dati dei contratti
    chiamate = Telefonata.objects.all().select_related('effettuataDa')
    
    # Recuperiamo il filtro dal form
    filtro_contratto = request.GET.get('contratto', '').strip()
    
    # Se l'utente ha cercato un numero, filtriamo
    if filtro_contratto:
        chiamate = chiamate.filter(effettuataDa__numero__icontains=filtro_contratto)
        
    context = {
        'chiamate': chiamate
    }
    return render(request, 'telefonate.html', context)

def gestione_sim(request):
    # Leggiamo dall'URL quale categoria di SIM vuole vedere l'utente. 
    # Se non c'è scritto nulla, mostriamo di default le SIM 'attive'
    stato = request.GET.get('stato', 'attive')
    
    # A seconda dello stato, peschiamo i dati dalla tabella corretta
    if stato == 'disponibili':
        sim_list = SIMNonAttiva.objects.all()
    elif stato == 'disattive':
        sim_list = SIMDisattiva.objects.all()
    else:
        stato = 'attive'  # Sistema di sicurezza
        sim_list = SIMAttiva.objects.all().select_related('associataA')
        
    context = {
        'sim_list': sim_list,
        'stato': stato
    }
    return render(request, 'sim.html', context)