# Manuale utente - Gestione Numeri Telefonici

## 1. Scopo del documento

Questo manuale guida l'installazione e l'avvio locale dell'applicazione **Gestione Numeri Telefonici**.

L'applicazione permette di consultare numeri telefonici, SIM e chiamate, filtrare i dati, esportare risultati in Excel e gestire lo storico delle SIM disattivate.

## 2. Prerequisiti

Prima di iniziare, verificare che il computer disponga di:

1. Python 3.12.
2. PostgreSQL installato e avviato.
3. Strumenti da riga di comando di PostgreSQL disponibili (`psql`, `pg_restore`).
4. Un browser web moderno.
5. Lo ZIP del progetto estratto in una cartella locale.

Non usare un IDE per l'installazione. Usare solo PowerShell su Windows o Terminale su macOS.

## 3. Contenuto della cartella del progetto

La cartella estratta deve contenere almeno:

```text
manage.py
requirements.txt
scripts/
database/generated/progetto2_db.backup
gestionale/
progettotwo/
```

Il file `database/generated/progetto2_db.backup` contiene i dati dimostrativi PostgreSQL. Non eliminarlo.

## 4. Installazione su Windows

### 4.1 Aprire PowerShell

1. Aprire la cartella del progetto.
2. Fare clic nella barra del percorso.
3. Scrivere `powershell`.
4. Premere Invio.

### 4.2 Verificare Python

Eseguire:

```powershell
python --version
```

Il risultato deve indicare Python 3.12.

Se il comando mostra una versione diversa, usare il comando associato a Python 3.12 oppure correggere l'installazione di Python.

### 4.3 Verificare PostgreSQL

Eseguire:

```powershell
psql --version
```

Il comando deve mostrare la versione di PostgreSQL.

Se `psql` non viene riconosciuto, aggiungere la cartella `bin` di PostgreSQL al PATH. Esempio:

```text
C:\Program Files\PostgreSQL\16\bin
```

### 4.4 Installare il progetto

Eseguire:

```powershell
scripts\install_windows.bat
```

Quando richiesto:

1. Inserire la password da usare per l'utente applicativo PostgreSQL.
2. Inserire l'utente amministratore PostgreSQL, di solito `postgres`.
3. Inserire la password dell'utente amministratore PostgreSQL.
4. Digitare `SI` per confermare la ricreazione del database locale.

Lo script crea l'ambiente virtuale, installa le dipendenze, crea il file `.env`, crea il database locale, ripristina i dati e verifica la coerenza del database.

### 4.5 Avviare il progetto

Eseguire:

```powershell
scripts\start_windows.bat
```

Aprire il browser all'indirizzo:

```text
http://127.0.0.1:8000/
```

### 4.6 Arrestare il progetto

Tornare nella finestra di PowerShell e premere:

```text
CTRL + C
```

## 5. Installazione su macOS

### 5.1 Aprire Terminale

1. Aprire Terminale.
2. Entrare nella cartella del progetto.

Esempio:

```bash
cd /percorso/della/cartella/progetto2
```

### 5.2 Verificare Python

Eseguire:

```bash
python3.12 --version
```

Il risultato deve indicare Python 3.12.

### 5.3 Verificare PostgreSQL

Eseguire:

```bash
psql --version
```

Il comando deve mostrare la versione di PostgreSQL.

Verificare anche `pg_restore`:

```bash
pg_restore --version
```

### 5.4 Rendere eseguibili gli script

Eseguire:

```bash
chmod +x scripts/install_macos.sh scripts/start_macos.sh
```

### 5.5 Installare il progetto

Eseguire:

```bash
./scripts/install_macos.sh
```

Quando richiesto:

1. Inserire la password da usare per l'utente applicativo PostgreSQL.
2. Inserire l'utente amministratore PostgreSQL, di solito `postgres`.
3. Inserire la password dell'utente amministratore PostgreSQL.
4. Digitare `SI` per confermare la ricreazione del database locale.

Lo script crea l'ambiente virtuale, installa le dipendenze, crea il file `.env`, crea il database locale, ripristina i dati e verifica la coerenza del database.

### 5.6 Avviare il progetto

Eseguire:

```bash
./scripts/start_macos.sh
```

Aprire il browser all'indirizzo:

```text
http://127.0.0.1:8000/
```

### 5.7 Arrestare il progetto

Tornare nel Terminale e premere:

```text
CTRL + C
```

## 6. Uso dell'applicazione

### 6.1 Panoramica

Usare la Panoramica per:

1. vedere statistiche generali;
2. cercare rapidamente un numero telefonico o una SIM;
3. aprire ricerche frequenti già configurate.

### 6.2 Numeri telefonici

Usare la pagina Numeri telefonici per:

1. cercare numeri con ricerca parziale;
2. filtrare per stato, piano, disponibilità, durata e chiamate registrate;
3. ordinare i risultati;
4. passare da vista a schede a vista tabellare;
5. esportare i risultati in Excel.

### 6.3 Chiamate

Usare la pagina Chiamate per:

1. filtrare per numero, periodo, ora, durata, piano e addebito;
2. ordinare per data, durata o costo;
3. aprire la scheda estesa del numero chiamante;
4. passare da vista a schede a vista tabellare;
5. esportare i risultati in Excel.

### 6.4 SIM

Usare la pagina SIM per:

1. cercare SIM attive, disponibili e disattivate;
2. filtrare per codice, numero collegato, stato, formato, piano e periodo;
3. visualizzare le SIM in schede o tabella;
4. esportare i risultati in Excel;
5. registrare una SIM disattivata nello storico.

## 7. Risoluzione problemi

### 7.1 Python non trovato

Verificare l'installazione di Python 3.12.

Su Windows eseguire:

```powershell
python --version
```

Su macOS eseguire:

```bash
python3.12 --version
```

Usare Python 3.12, non versioni precedenti o successive.

### 7.2 PostgreSQL non trovato

Eseguire:

```bash
psql --version
```

Se il comando non viene trovato, verificare che i Command Line Tools di PostgreSQL siano installati e presenti nel PATH.

### 7.3 Connessione al database fallita

Controllare che PostgreSQL sia avviato.

Su Windows eseguire PowerShell come amministratore:

```powershell
Get-Service postgresql*
```

Se il servizio è fermo, avviarlo:

```powershell
Start-Service postgresql-x64-16
```

Su macOS usare il metodo previsto dall'installazione di PostgreSQL presente sul computer.

### 7.4 Backup non trovato

Controllare che esista il file:

```text
database/generated/progetto2_db.backup
```

Se il file manca, copiare il backup nella cartella indicata e ripetere l'installazione.

### 7.5 Password PostgreSQL errata

Ripetere lo script di installazione e inserire la password corretta dell'utente amministratore PostgreSQL.

### 7.6 Porta 8000 occupata

Avviare Django su una porta diversa:

```bash
python manage.py runserver 8001
```

Aprire il browser all'indirizzo:

```text
http://127.0.0.1:8001/
```

### 7.7 Pagina senza dati

Controllare che lo script di installazione abbia ripristinato il backup PostgreSQL.

Eseguire:

```bash
python manage.py verify_import
```

La verifica deve terminare con:

```text
Verifica completata: database coerente.
```
