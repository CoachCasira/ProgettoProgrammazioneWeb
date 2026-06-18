# Checklist di collaudo

## Ambiente

- [ ] Python 3.12 disponibile.
- [ ] PostgreSQL installato e avviato.
- [ ] `psql` disponibile da terminale.
- [ ] `pg_restore` disponibile da terminale.
- [ ] File `database/generated/progetto2_db.backup` presente.

## Installazione

- [ ] Script Windows eseguito senza errori.
- [ ] Script macOS eseguito senza errori.
- [ ] Ambiente virtuale creato.
- [ ] Dipendenze installate da `requirements.txt`.
- [ ] File `.env` creato localmente.
- [ ] Database PostgreSQL creato.
- [ ] Backup PostgreSQL ripristinato.
- [ ] `python manage.py verify_import` completato con database coerente.

## Avvio

- [ ] Script di avvio Windows funzionante.
- [ ] Script di avvio macOS funzionante.
- [ ] Applicazione raggiungibile su `http://127.0.0.1:8000/`.

## Panoramica

- [ ] Statistiche generali visibili.
- [ ] Ricerca rapida funzionante con numero telefonico.
- [ ] Ricerca rapida funzionante con codice SIM.
- [ ] Messaggio di errore chiaro per input non valido.
- [ ] Ricerche frequenti funzionanti.

## Numeri telefonici

- [ ] Ricerca live funzionante.
- [ ] Filtri principali funzionanti.
- [ ] Ordinamenti funzionanti.
- [ ] Vista a schede corretta.
- [ ] Vista tabellare corretta.
- [ ] Card estesa funzionante.
- [ ] Esportazione Excel funzionante.

## Chiamate

- [ ] Ricerca live funzionante.
- [ ] Filtri principali funzionanti.
- [ ] Ordinamenti funzionanti.
- [ ] Vista a schede corretta.
- [ ] Vista tabellare corretta.
- [ ] Doppia freccia verso l'ultimo blocco veloce.
- [ ] Doppia freccia verso il primo blocco veloce.
- [ ] Clic sul numero chiamante apre la modale del numero.
- [ ] Esportazione Excel funzionante.

## SIM

- [ ] Ricerca live funzionante.
- [ ] Filtro stato SIM funzionante.
- [ ] Filtri codice, numero, formato, piano e periodo funzionanti.
- [ ] Vista a schede corretta.
- [ ] Vista tabellare corretta.
- [ ] Esportazione Excel funzionante.
- [ ] Creazione SIM disattivata funzionante.
- [ ] Modifica SIM disattivata funzionante.
- [ ] Eliminazione SIM disattivata funzionante.

## Responsive

- [ ] Numeri telefonici leggibile su schermo ridotto.
- [ ] Chiamate leggibile su schermo ridotto.
- [ ] SIM leggibile su schermo ridotto.
- [ ] Tabelle con scroll orizzontale quando necessario.
