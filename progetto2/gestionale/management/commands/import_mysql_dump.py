"""Importazione massiva del dump MySQL del primo progetto in PostgreSQL.

Il comando legge direttamente un file ``.sql`` esportato da phpMyAdmin oppure
un archivio ``.zip`` che contiene un solo file SQL. I dati vengono trasferiti
con PostgreSQL COPY, evitando milioni di INSERT eseguiti tramite ORM.
"""

from __future__ import annotations

import io
import re
import time
import zipfile
from contextlib import contextmanager
from pathlib import Path
from typing import Iterator, TextIO

from django.core.management.base import BaseCommand, CommandError
from django.db import connection, transaction
from psycopg import sql


INSERT_RE = re.compile(r"^INSERT INTO `([^`]+)` \((.+)\) VALUES$")

# Nome tabella MySQL -> (tabella PostgreSQL, colonne attese nel dump).
TABLES: dict[str, tuple[str, tuple[str, ...]]] = {
    "ContrattoTelefonico": (
        "contratto_telefonico",
        ("numero", "dataAttivazione", "tipo", "minutiResidui", "creditoResiduo"),
    ),
    "SIMAttiva": (
        "sim_attiva",
        ("codice", "tipoSIM", "associataA", "dataAttivazione"),
    ),
    "SIMDisattiva": (
        "sim_disattiva",
        (
            "codice",
            "tipoSIM",
            "eraAssociataA",
            "dataAttivazione",
            "dataDisattivazione",
        ),
    ),
    "SIMNonAttiva": (
        "sim_non_attiva",
        ("codice", "tipoSIM"),
    ),
    "StatisticheContratto": (
        "statistiche_contratto",
        (
            "numero",
            "numeroTelefonate",
            "durataTotale",
            "addebitoTotale",
            "ultimaTelefonata",
        ),
    ),
    "StatisticheSIM": (
        "statistiche_sim",
        ("codice", "stato", "numeroChiamate"),
    ),
    "StatisticheTelefonate": (
        "statistiche_telefonate",
        (
            "id",
            "totaleTelefonate",
            "durataTotale",
            "durataMedia",
            "addebitoTotale",
            "aggiornatoIl",
        ),
    ),
    "Telefonata": (
        "telefonata",
        ("id", "effettuataDa", "data", "ora", "durata", "costo"),
    ),
}

TARGET_TABLES = tuple(table for table, _ in TABLES.values())

MYSQL_ESCAPES = {
    "0": "\0",
    "b": "\b",
    "n": "\n",
    "r": "\r",
    "t": "\t",
    "Z": "\x1a",
}


@contextmanager
def open_sql_source(path: Path) -> Iterator[TextIO]:
    """Apre un dump SQL semplice o il singolo SQL contenuto in uno ZIP."""

    if not path.is_file():
        raise CommandError(f"File non trovato: {path}")

    if zipfile.is_zipfile(path):
        archive = zipfile.ZipFile(path)
        members = [name for name in archive.namelist() if name.lower().endswith(".sql")]
        if len(members) != 1:
            archive.close()
            raise CommandError(
                "L'archivio ZIP deve contenere esattamente un file con estensione .sql."
            )
        binary = archive.open(members[0], "r")
        text = io.TextIOWrapper(binary, encoding="utf-8", errors="strict", newline="")
        try:
            yield text
        finally:
            text.close()
            archive.close()
        return

    with path.open("r", encoding="utf-8", errors="strict", newline="") as text:
        yield text


def parse_columns(raw_columns: str) -> tuple[str, ...]:
    """Estrae i nomi racchiusi tra backtick dall'intestazione INSERT."""

    return tuple(re.findall(r"`([^`]+)`", raw_columns))


def parse_mysql_row(raw_line: str) -> tuple[object | None, ...]:
    """Converte una tupla VALUES di phpMyAdmin in valori Python.

    Il dump del progetto contiene una tupla per riga. Sono supportati stringhe
    MySQL con escape, NULL, numeri e letterali esadecimali ``0x...`` usati per
    il codice della tabella StatisticheSIM.
    """

    line = raw_line.strip()
    if line.endswith(",") or line.endswith(";"):
        line = line[:-1].rstrip()
    if len(line) < 2 or line[0] != "(" or line[-1] != ")":
        raise ValueError(f"Riga VALUES non riconosciuta: {raw_line[:120]!r}")

    content = line[1:-1]
    values: list[object | None] = []
    index = 0
    length = len(content)

    while index < length:
        while index < length and content[index].isspace():
            index += 1

        if content.startswith("NULL", index):
            values.append(None)
            index += 4
        elif content.startswith("0x", index):
            end = index + 2
            while end < length and content[end] != ",":
                end += 1
            hexadecimal = content[index + 2 : end].strip()
            try:
                values.append(bytes.fromhex(hexadecimal).decode("utf-8"))
            except (ValueError, UnicodeDecodeError) as exc:
                raise ValueError("Letterale esadecimale MySQL non valido.") from exc
            index = end
        elif index < length and content[index] == "'":
            index += 1
            chars: list[str] = []
            while index < length:
                char = content[index]
                if char == "\\":
                    index += 1
                    if index >= length:
                        raise ValueError("Escape MySQL incompleto.")
                    escaped = content[index]
                    chars.append(MYSQL_ESCAPES.get(escaped, escaped))
                    index += 1
                elif char == "'":
                    # Supporta anche l'apostrofo SQL raddoppiato.
                    if index + 1 < length and content[index + 1] == "'":
                        chars.append("'")
                        index += 2
                    else:
                        index += 1
                        break
                else:
                    chars.append(char)
                    index += 1
            else:
                raise ValueError("Stringa MySQL non terminata.")
            values.append("".join(chars))
        else:
            end = index
            while end < length and content[end] != ",":
                end += 1
            token = content[index:end].strip()
            if not token:
                raise ValueError("Valore MySQL vuoto non valido.")
            # PostgreSQL COPY converte direttamente la rappresentazione testuale.
            values.append(token)
            index = end

        while index < length and content[index].isspace():
            index += 1
        if index < length:
            if content[index] != ",":
                raise ValueError(f"Separatore inatteso vicino a: {content[index:index+30]!r}")
            index += 1

    return tuple(values)


class Command(BaseCommand):
    help = (
        "Importa in PostgreSQL il dump MySQL del primo progetto usando COPY. "
        "Accetta un file .sql o un archivio .zip contenente un solo SQL."
    )

    def add_arguments(self, parser) -> None:
        parser.add_argument("source", help="Percorso del dump MySQL .sql o .zip")
        parser.add_argument(
            "--replace",
            action="store_true",
            help="Svuota prima le tabelle del dominio e reimporta tutti i dati.",
        )
        parser.add_argument(
            "--progress-every",
            type=int,
            default=100_000,
            metavar="N",
            help="Mostra l'avanzamento ogni N righe (predefinito: 100000).",
        )

    def handle(self, *args, **options) -> None:
        source = Path(options["source"]).expanduser().resolve()
        progress_every = max(1, options["progress_every"])

        connection.ensure_connection()
        if connection.vendor != "postgresql":
            raise CommandError("Il comando può essere eseguito soltanto su PostgreSQL.")

        if options["replace"]:
            self._truncate_domain_tables()
        else:
            non_empty = self._non_empty_tables()
            if non_empty:
                joined = ", ".join(non_empty)
                raise CommandError(
                    "Il database contiene già dati nelle tabelle del dominio: "
                    f"{joined}. Usare --replace soltanto se si vuole ricominciare."
                )

        self.stdout.write(f"Sorgente: {source}")
        started_at = time.monotonic()
        imported: dict[str, int] = {name: 0 for name in TABLES}

        with open_sql_source(source) as stream:
            line_number = 0
            iterator = iter(stream)
            for raw_line in iterator:
                line_number += 1
                match = INSERT_RE.match(raw_line.rstrip("\r\n"))
                if not match:
                    continue

                mysql_table = match.group(1)
                dump_columns = parse_columns(match.group(2))
                if mysql_table not in TABLES:
                    line_number += self._skip_insert_block(iterator)
                    continue

                postgres_table, expected_columns = TABLES[mysql_table]
                if dump_columns != expected_columns:
                    raise CommandError(
                        f"Colonne inattese per {mysql_table} alla riga {line_number}. "
                        f"Attese: {expected_columns}; trovate: {dump_columns}."
                    )

                block_count, consumed = self._copy_insert_block(
                    iterator=iterator,
                    mysql_table=mysql_table,
                    postgres_table=postgres_table,
                    columns=expected_columns,
                    already_imported=imported[mysql_table],
                    progress_every=progress_every,
                )
                line_number += consumed
                imported[mysql_table] += block_count

        self._reset_telefonata_sequence()
        elapsed = time.monotonic() - started_at

        self.stdout.write("")
        self.stdout.write(self.style.SUCCESS("Importazione completata."))
        for table_name in TABLES:
            self.stdout.write(f"- {table_name}: {imported[table_name]:,}".replace(",", "."))
        self.stdout.write(f"Tempo totale: {elapsed:.1f} secondi")

    def _non_empty_tables(self) -> list[str]:
        non_empty: list[str] = []
        with connection.cursor() as cursor:
            for table in TARGET_TABLES:
                query = sql.SQL("SELECT EXISTS (SELECT 1 FROM {} LIMIT 1)").format(
                    sql.Identifier(table)
                )
                cursor.execute(query)
                if cursor.fetchone()[0]:
                    non_empty.append(table)
        return non_empty

    def _truncate_domain_tables(self) -> None:
        identifiers = sql.SQL(", ").join(sql.Identifier(name) for name in TARGET_TABLES)
        statement = sql.SQL("TRUNCATE TABLE {} RESTART IDENTITY CASCADE").format(
            identifiers
        )
        with transaction.atomic():
            with connection.cursor() as cursor:
                cursor.execute(statement)
        self.stdout.write("Tabelle del dominio svuotate.")

    @staticmethod
    def _skip_insert_block(iterator: Iterator[str]) -> int:
        consumed = 0
        for line in iterator:
            consumed += 1
            if line.rstrip().endswith(";"):
                break
        return consumed

    def _copy_insert_block(
        self,
        *,
        iterator: Iterator[str],
        mysql_table: str,
        postgres_table: str,
        columns: tuple[str, ...],
        already_imported: int,
        progress_every: int,
    ) -> tuple[int, int]:
        copy_statement = sql.SQL("COPY {} ({}) FROM STDIN").format(
            sql.Identifier(postgres_table),
            sql.SQL(", ").join(sql.Identifier(column) for column in columns),
        )
        block_count = 0
        consumed = 0
        block_started = time.monotonic()

        with transaction.atomic():
            with connection.cursor() as cursor:
                cursor.execute("SET LOCAL synchronous_commit TO OFF")
                with cursor.copy(copy_statement) as copier:
                    for raw_row in iterator:
                        consumed += 1
                        stripped = raw_row.strip()
                        if not stripped:
                            continue
                        if not stripped.startswith("("):
                            raise CommandError(
                                f"Riga inattesa durante l'importazione di {mysql_table}: "
                                f"{stripped[:120]}"
                            )
                        try:
                            values = parse_mysql_row(stripped)
                        except ValueError as exc:
                            raise CommandError(
                                f"Errore nel dump durante {mysql_table}, riga del blocco "
                                f"{block_count + 1}: {exc}"
                            ) from exc
                        if len(values) != len(columns):
                            raise CommandError(
                                f"Numero di valori non valido per {mysql_table}: "
                                f"attesi {len(columns)}, trovati {len(values)}."
                            )
                        copier.write_row(values)
                        block_count += 1

                        total = already_imported + block_count
                        if total % progress_every == 0:
                            elapsed = time.monotonic() - block_started
                            self.stdout.write(
                                f"{mysql_table}: {total:,} righe importate "
                                f"({elapsed:.1f} s nel blocco)".replace(",", ".")
                            )

                        if stripped.endswith(";"):
                            break

        return block_count, consumed

    @staticmethod
    def _reset_telefonata_sequence() -> None:
        """Allinea la sequenza BigAutoField al massimo ID importato."""

        with transaction.atomic():
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    SELECT setval(
                        pg_get_serial_sequence('telefonata', 'id'),
                        COALESCE((SELECT MAX(id) FROM telefonata), 1),
                        EXISTS (SELECT 1 FROM telefonata)
                    )
                    """
                )
