#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -x ".venv/bin/python" ]; then
  echo "ERRORE: ambiente virtuale non trovato. Eseguire prima ./scripts/install_macos.sh"
  exit 1
fi

.venv/bin/python manage.py runserver 127.0.0.1:8000
