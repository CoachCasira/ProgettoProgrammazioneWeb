#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

echo "Installazione progetto Gestione Numeri Telefonici"

if command -v python3.12 >/dev/null 2>&1; then
  PYTHON="python3.12"
elif command -v python3 >/dev/null 2>&1; then
  PYTHON="python3"
else
  echo "ERRORE: Python 3.12 non trovato."
  exit 1
fi

"$PYTHON" - <<'PY'
import sys
if sys.version_info[:2] != (3, 12):
    raise SystemExit("ERRORE: il comando Python disponibile non usa Python 3.12.")
PY

if [ ! -x ".venv/bin/python" ]; then
  echo "Creazione ambiente virtuale..."
  "$PYTHON" -m venv .venv
fi

echo "Installazione dipendenze Python..."
.venv/bin/python -m pip install -r requirements.txt

echo "Preparazione database PostgreSQL..."
.venv/bin/python scripts/prepare_database.py

echo ""
echo "Installazione completata."
echo "Avviare il sito con ./scripts/start_macos.sh"
