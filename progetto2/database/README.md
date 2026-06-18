# Database PostgreSQL

La consegna usa PostgreSQL come database locale.

## File sorgenti locali

La cartella `source` è riservata al dump MySQL del primo progetto usato solo durante la conversione iniziale. Il dump contiene molti dati e non viene versionato su GitHub.

Per ripetere la conversione MySQL -> PostgreSQL in ambiente di sviluppo:

```powershell
python manage.py import_mysql_dump "database/source/my_teoscbarce.zip" --replace
python manage.py verify_import
```

## Backup di consegna

La cartella `generated` è riservata al backup PostgreSQL compresso:

```text
progetto2_db.backup
```

Il backup viene ripristinato dagli script di installazione tramite `pg_restore`.
Il file non viene versionato su GitHub, ma deve essere incluso nello ZIP finale di consegna.

Conteggi attesi dopo il ripristino:

- contratti telefonici: 647
- telefonate: 3.326.428
- SIM attive: 550
- SIM disattivate: 392
- SIM non attive: 337
- statistiche contratti: 643
- statistiche SIM: 1.279
- statistiche telefonate: 1
