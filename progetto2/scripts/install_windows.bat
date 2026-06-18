@echo off
setlocal
cd /d "%~dp0\.."

echo Installazione progetto Gestione Numeri Telefonici

echo.
echo Controllo Python 3.12...
python -c "import sys; raise SystemExit(0 if sys.version_info[:2] == (3, 12) else 1)" >nul 2>nul
if errorlevel 1 (
    echo ERRORE: Python 3.12 non risulta disponibile come comando python.
    echo Verifica l'installazione di Python 3.12 e riprova.
    exit /b 1
)

if not exist ".venv\Scripts\python.exe" (
    echo Creazione ambiente virtuale...
    python -m venv .venv
    if errorlevel 1 exit /b 1
)

echo Installazione dipendenze Python...
".venv\Scripts\python.exe" -m pip install -r requirements.txt
if errorlevel 1 exit /b 1

echo Preparazione database PostgreSQL...
".venv\Scripts\python.exe" scripts\prepare_database.py
if errorlevel 1 exit /b 1

echo.
echo Installazione completata.
echo Avviare il sito con scripts\start_windows.bat
