"""Impostazioni Django del progetto Gestione Numeri Telefonici.

La configurazione è pensata per Python 3.12, Django 5.2 LTS e PostgreSQL.
I valori specifici della macchina locale vengono letti dal file ``.env``, che
non deve essere versionato nel repository.
"""

from __future__ import annotations

import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent


def _load_env_file(path: Path) -> None:
    """Carica semplici coppie CHIAVE=VALORE senza dipendenze esterne.

    Le variabili già definite nel sistema operativo hanno precedenza sui
    valori del file. Sono accettati valori racchiusi tra apici singoli o doppi.
    """

    if not path.is_file():
        return

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        if key:
            os.environ.setdefault(key, value)


_load_env_file(BASE_DIR / ".env")


def _env_bool(name: str, default: bool = False) -> bool:
    return os.getenv(name, str(default)).strip().lower() in {
        "1",
        "true",
        "yes",
        "on",
    }


def _env_list(name: str, default: str = "") -> list[str]:
    return [item.strip() for item in os.getenv(name, default).split(",") if item.strip()]


SECRET_KEY = os.getenv(
    "DJANGO_SECRET_KEY",
    "django-insecure-solo-sviluppo-locale-sostituire-nel-file-env",
)
DEBUG = _env_bool("DJANGO_DEBUG", True)
ALLOWED_HOSTS = _env_list("DJANGO_ALLOWED_HOSTS", "127.0.0.1,localhost")

INSTALLED_APPS = [
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "gestionale",
]

MIDDLEWARE = [
    "django.middleware.security.SecurityMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
]

ROOT_URLCONF = "progettotwo.urls"

TEMPLATES = [
    {
        "BACKEND": "django.template.backends.django.DjangoTemplates",
        "DIRS": [],
        "APP_DIRS": True,
        "OPTIONS": {
            "context_processors": [
                "django.template.context_processors.request",
                "django.contrib.messages.context_processors.messages",
            ],
        },
    },
]

WSGI_APPLICATION = "progettotwo.wsgi.application"
ASGI_APPLICATION = "progettotwo.asgi.application"

DATABASES = {
    "default": {
        "ENGINE": "django.db.backends.postgresql",
        "NAME": os.getenv("POSTGRES_DB", "progetto2_db"),
        "USER": os.getenv("POSTGRES_USER", "progetto2_user"),
        "PASSWORD": os.getenv("POSTGRES_PASSWORD", ""),
        "HOST": os.getenv("POSTGRES_HOST", "127.0.0.1"),
        "PORT": os.getenv("POSTGRES_PORT", "5432"),
        "CONN_MAX_AGE": 60,
        "CONN_HEALTH_CHECKS": True,
    }
}

AUTH_PASSWORD_VALIDATORS: list[dict[str, str]] = []

LANGUAGE_CODE = "it-it"
TIME_ZONE = "Europe/Rome"
USE_I18N = True
USE_TZ = True

STATIC_URL = "/static/"
DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"
