# Conversione del database

La cartella `source` è riservata al dump MySQL locale del primo progetto.
Il dump contiene dati di grandi dimensioni e non viene versionato su GitHub.

Dopo avere applicato le migration PostgreSQL, importare il dump con:

```powershell
python manage.py import_mysql_dump "database/source/my_teoscbarce.zip"
```

Il comando accetta anche un file `.sql`. Per svuotare le tabelle del dominio e
ripetere una conversione usare esplicitamente `--replace`.

Al termine verificare conteggi, statistiche e disgiunzione dei codici SIM:

```powershell
python manage.py verify_import
```

La cartella `generated` verrà utilizzata in una fase successiva per il backup
PostgreSQL destinato alla consegna.
