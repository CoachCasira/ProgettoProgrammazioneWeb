# Scelte progettuali - Secondo progetto

## Caso scelto

Il progetto segue il **Caso A**, cioè la ristrutturazione del primo progetto usando Python, Django, Bootstrap e PostgreSQL.

L'obiettivo non è creare una nuova applicazione, ma trasferire il progetto già realizzato in una nuova architettura, mantenendo dominio, funzionalità principali, impostazione grafica e logica di consultazione.

## Architettura applicativa

L'applicazione è stata realizzata con Django 5.2 LTS e Python 3.12. La scelta di una versione LTS di Django rende il progetto più stabile e adatto a una verifica locale, evitando dipendenze troppo recenti o non necessarie.

La struttura separa:

- modelli del dominio in `models.py`;
- logica di interrogazione in `services.py`;
- gestione delle richieste in `views.py`;
- form e validazioni in `forms.py`;
- esportazioni Excel in `excel.py`;
- template HTML in `templates/`;
- file CSS, JavaScript e immagini in `static/assets/`.

Questa separazione sostituisce la logica PHP del primo progetto con una struttura Django più ordinata, mantenendo però lo stesso comportamento per l'utente.

## Database

Il database è stato convertito da MySQL a PostgreSQL, come richiesto per il secondo progetto.

Sono state mantenute le tabelle funzionali principali:

- `contratto_telefonico`;
- `telefonata`;
- `sim_attiva`;
- `sim_disattiva`;
- `sim_non_attiva`.

Sono state mantenute anche le tabelle statistiche necessarie alle prestazioni:

- `statistiche_contratto`;
- `statistiche_sim`;
- `statistiche_telefonate`.

Le tabelle statistiche evitano di ricalcolare continuamente aggregazioni su oltre tre milioni di telefonate. In questo modo la Panoramica, i conteggi, gli ordinamenti e alcuni filtri possono usare dati già sintetizzati.

## Importazione e consegna dei dati

Il dump MySQL originale è stato usato solo durante la conversione iniziale. Dopo la conversione, il database PostgreSQL è stato salvato in un backup custom compresso tramite `pg_dump`.

Il backup PostgreSQL ha dimensione contenuta e viene ripristinato tramite `pg_restore`. Nei test locali il ripristino completo richiede meno di un minuto, quindi rimane compatibile con l'obiettivo di installazione rapida.

Il backup non viene caricato nel repository GitHub per evitare di appesantire il versionamento. Viene invece incluso nello ZIP finale di consegna nella cartella:

```text
database/generated/progetto2_db.backup
```

## Bootstrap e CSS personalizzato

Bootstrap è incluso localmente nel progetto e viene caricato prima del CSS personalizzato.

Il CSS principale del primo progetto è stato mantenuto per conservare identità visiva, palette, card, filtri, tabelle e componenti grafici già progettati.

Bootstrap viene usato in modo mirato come supporto per:

- accessibilità;
- allineamenti controllati;
- spaziature;
- toolbar;
- comportamenti responsive non invasivi.

La scelta evita di sostituire il foglio di stile principale e impedisce che le classi Bootstrap modifichino pesantemente la grafica già validata nel primo progetto.

## Prestazioni

La pagina Chiamate contiene oltre tre milioni di record. Per questo motivo il progetto Django non carica mai tutte le chiamate in memoria.

Sono state usate:

- paginazione server-side;
- caricamento progressivo dei risultati;
- endpoint AJAX per i blocchi di righe;
- conteggi separati;
- tabelle statistiche;
- query ottimizzate con relazioni già selezionate quando utile.

La logica riproduce il comportamento del primo progetto, evitando blocchi quando l'utente usa frecce, filtri o ordinamenti.

## Funzionalità mantenute

Sono state mantenute le funzioni principali del primo progetto:

- Panoramica operativa;
- ricerca rapida;
- ricerche frequenti;
- ricerca e filtri su numeri telefonici;
- ricerca e filtri su chiamate;
- ricerca e filtri su SIM;
- vista a schede e vista tabellare;
- schede estese in modale;
- esportazioni Excel;
- CRUD dello storico delle SIM disattivate;
- messaggi di errore e conferma;
- navigazione tra dati collegati.

## Installazione

L'installazione avviene da riga di comando, senza IDE. Sono presenti script separati per Windows e macOS.

Gli script:

1. verificano Python 3.12;
2. creano l'ambiente virtuale;
3. installano le dipendenze;
4. generano il file `.env` locale;
5. creano utente e database PostgreSQL;
6. ripristinano il backup;
7. verificano la coerenza dei dati;
8. permettono l'avvio locale dell'applicazione.

Questa impostazione rende l'installazione ripetibile e riduce le operazioni manuali richieste all'utente.
