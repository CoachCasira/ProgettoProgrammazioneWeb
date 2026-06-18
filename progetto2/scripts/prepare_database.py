"""Prepara il database PostgreSQL locale del secondo progetto.

Lo script è pensato per l'installazione da riga di comando su Windows e macOS.
Crea o ricrea il database applicativo, ripristina il backup PostgreSQL e genera
il file .env locale senza salvare password nel repository.
"""

from __future__ import annotations

import getpass
import os
import shutil
import subprocess
import sys
from pathlib import Path
from secrets import token_urlsafe

import psycopg
from psycopg import sql

ROOT = Path(__file__).resolve().parent.parent
ENV_PATH = ROOT / ".env"
BACKUP_PATH = ROOT / "database" / "generated" / "progetto2_db.backup"

DEFAULTS = {
    "DJANGO_DEBUG": "True",
    "DJANGO_ALLOWED_HOSTS": "127.0.0.1,localhost",
    "POSTGRES_DB": "progetto2_db",
    "POSTGRES_USER": "progetto2_user",
    "POSTGRES_HOST": "127.0.0.1",
    "POSTGRES_PORT": "5432",
}


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("'\"")
    return values


def write_env(values: dict[str, str]) -> None:
    content = f"""DJANGO_SECRET_KEY={values['DJANGO_SECRET_KEY']}
DJANGO_DEBUG={values['DJANGO_DEBUG']}
DJANGO_ALLOWED_HOSTS={values['DJANGO_ALLOWED_HOSTS']}

POSTGRES_DB={values['POSTGRES_DB']}
POSTGRES_USER={values['POSTGRES_USER']}
POSTGRES_PASSWORD={values['POSTGRES_PASSWORD']}
POSTGRES_HOST={values['POSTGRES_HOST']}
POSTGRES_PORT={values['POSTGRES_PORT']}
"""
    ENV_PATH.write_text(content, encoding="utf-8")


def ask_text(label: str, default: str) -> str:
    text = input(f"{label} [{default}]: ").strip()
    return text or default


def ask_password(label: str, current_value: str | None = None) -> str:
    suffix = " [invio per mantenere quella del file .env]" if current_value else ""
    while True:
        value = getpass.getpass(f"{label}{suffix}: ")
        if value:
            return value
        if current_value:
            return current_value
        print("La password non può essere vuota.")


def find_pg_restore() -> str:
    executable = shutil.which("pg_restore")
    if executable:
        return executable

    windows_candidates = [
        Path(r"C:\Program Files\PostgreSQL\16\bin\pg_restore.exe"),
        Path(r"C:\Program Files\PostgreSQL\15\bin\pg_restore.exe"),
        Path(r"C:\Program Files\PostgreSQL\14\bin\pg_restore.exe"),
    ]
    for candidate in windows_candidates:
        if candidate.exists():
            return str(candidate)

    raise SystemExit(
        "pg_restore non è stato trovato. Verifica che PostgreSQL Command Line Tools "
        "sia installato e presente nel PATH."
    )


def connect_admin(admin_user: str, admin_password: str, host: str, port: str):
    return psycopg.connect(
        dbname="postgres",
        user=admin_user,
        password=admin_password,
        host=host,
        port=port,
        autocommit=True,
    )


def database_exists(cur, db_name: str) -> bool:
    cur.execute("SELECT 1 FROM pg_database WHERE datname = %s", (db_name,))
    return cur.fetchone() is not None


def role_exists(cur, user_name: str) -> bool:
    cur.execute("SELECT 1 FROM pg_roles WHERE rolname = %s", (user_name,))
    return cur.fetchone() is not None


def recreate_database(values: dict[str, str], admin_user: str, admin_password: str) -> None:
    db_name = values["POSTGRES_DB"]
    app_user = values["POSTGRES_USER"]
    app_password = values["POSTGRES_PASSWORD"]
    host = values["POSTGRES_HOST"]
    port = values["POSTGRES_PORT"]

    print("\nPreparazione del database PostgreSQL...")
    with connect_admin(admin_user, admin_password, host, port) as connection:
        with connection.cursor() as cur:
            if role_exists(cur, app_user):
                cur.execute(
                    sql.SQL("ALTER ROLE {} WITH LOGIN PASSWORD %s").format(
                        sql.Identifier(app_user)
                    ),
                    (app_password,),
                )
                print(f"- ruolo PostgreSQL già presente: {app_user}")
            else:
                cur.execute(
                    sql.SQL("CREATE ROLE {} WITH LOGIN PASSWORD %s").format(
                        sql.Identifier(app_user)
                    ),
                    (app_password,),
                )
                print(f"- ruolo PostgreSQL creato: {app_user}")

            if database_exists(cur, db_name):
                cur.execute(
                    "SELECT pg_terminate_backend(pid) "
                    "FROM pg_stat_activity "
                    "WHERE datname = %s AND pid <> pg_backend_pid()",
                    (db_name,),
                )
                cur.execute(sql.SQL("DROP DATABASE {}").format(sql.Identifier(db_name)))
                print(f"- database precedente eliminato: {db_name}")

            cur.execute(
                sql.SQL(
                    "CREATE DATABASE {} WITH OWNER = {} ENCODING = 'UTF8' TEMPLATE = template0"
                ).format(sql.Identifier(db_name), sql.Identifier(app_user))
            )
            print(f"- database creato: {db_name}")

            cur.execute(
                sql.SQL("ALTER ROLE {} SET client_encoding TO 'UTF8'").format(
                    sql.Identifier(app_user)
                )
            )
            cur.execute(
                sql.SQL("ALTER ROLE {} SET timezone TO 'Europe/Rome'").format(
                    sql.Identifier(app_user)
                )
            )


def restore_backup(values: dict[str, str]) -> None:
    if not BACKUP_PATH.exists():
        raise SystemExit(
            f"Backup non trovato: {BACKUP_PATH}\n"
            "Inserisci il file progetto2_db.backup in database/generated prima dell'installazione."
        )

    pg_restore = find_pg_restore()
    env = os.environ.copy()
    env["PGPASSWORD"] = values["POSTGRES_PASSWORD"]

    command = [
        pg_restore,
        "-U",
        values["POSTGRES_USER"],
        "-h",
        values["POSTGRES_HOST"],
        "-p",
        values["POSTGRES_PORT"],
        "-d",
        values["POSTGRES_DB"],
        "--no-owner",
        "--no-privileges",
        "--exit-on-error",
        str(BACKUP_PATH),
    ]

    print("\nRipristino del backup PostgreSQL...")
    subprocess.run(command, cwd=ROOT, env=env, check=True)
    print("- backup ripristinato correttamente")


def run_django_check() -> None:
    print("\nVerifica Django...")
    subprocess.run([sys.executable, "manage.py", "check"], cwd=ROOT, check=True)
    subprocess.run([sys.executable, "manage.py", "verify_import"], cwd=ROOT, check=True)


def main() -> None:
    print("Installazione database - Gestione Numeri Telefonici")
    print("Questo comando ricrea il database locale e ripristina i dati dimostrativi.")

    values = DEFAULTS | load_env(ENV_PATH)
    values.setdefault("DJANGO_SECRET_KEY", token_urlsafe(50))

    values["POSTGRES_HOST"] = ask_text("Host PostgreSQL", values["POSTGRES_HOST"])
    values["POSTGRES_PORT"] = ask_text("Porta PostgreSQL", values["POSTGRES_PORT"])
    values["POSTGRES_DB"] = ask_text("Nome database applicativo", values["POSTGRES_DB"])
    values["POSTGRES_USER"] = ask_text("Utente applicativo PostgreSQL", values["POSTGRES_USER"])
    values["POSTGRES_PASSWORD"] = ask_password(
        "Password utente applicativo PostgreSQL", values.get("POSTGRES_PASSWORD")
    )

    admin_user = ask_text("Utente amministratore PostgreSQL", "postgres")
    admin_password = ask_password("Password utente amministratore PostgreSQL")

    print(
        "\nATTENZIONE: il database applicativo indicato verrà eliminato e ricreato, "
        "se già presente."
    )
    confirm = input("Digitare SI per continuare: ").strip().upper()
    if confirm != "SI":
        raise SystemExit("Operazione annullata.")

    write_env(values)
    recreate_database(values, admin_user, admin_password)
    restore_backup(values)
    run_django_check()

    print("\nInstallazione completata.")
    print("Avvia l'applicazione con:")
    print("  scripts\\start_windows.bat")
    print("oppure su macOS:")
    print("  ./scripts/start_macos.sh")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nOperazione interrotta dall'utente.")
        raise SystemExit(1)
