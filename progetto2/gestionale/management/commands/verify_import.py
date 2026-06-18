"""Verifica strutturale e quantitativa del database PostgreSQL importato."""

from __future__ import annotations

from decimal import Decimal

from django.core.management.base import BaseCommand, CommandError
from django.db import connection


EXPECTED_COUNTS = {
    "contratto_telefonico": 647,
    "telefonata": 3_326_428,
    "sim_attiva": 550,
    "sim_disattiva": 392,
    "sim_non_attiva": 337,
    "statistiche_contratto": 643,
    "statistiche_sim": 1_279,
    "statistiche_telefonate": 1,
}

EXPECTED_TOTAL_DURATION = 1_145_726_398
EXPECTED_TOTAL_COST = Decimal("1575150.09")


class Command(BaseCommand):
    help = "Verifica conteggi, statistiche e coerenza del database importato."

    def handle(self, *args, **options) -> None:
        connection.ensure_connection()
        if connection.vendor != "postgresql":
            raise CommandError("La verifica può essere eseguita soltanto su PostgreSQL.")

        errors: list[str] = []
        self.stdout.write("Controllo dei conteggi:")
        with connection.cursor() as cursor:
            for table, expected in EXPECTED_COUNTS.items():
                cursor.execute(f'SELECT COUNT(*) FROM "{table}"')
                actual = cursor.fetchone()[0]
                marker = "OK" if actual == expected else "ERRORE"
                self.stdout.write(
                    f"- {table}: {actual:,} / {expected:,} [{marker}]".replace(",", ".")
                )
                if actual != expected:
                    errors.append(
                        f"{table}: attesi {expected}, trovati {actual}"
                    )

            self.stdout.write("\nControllo delle telefonate:")
            cursor.execute(
                """
                SELECT COUNT(*), COALESCE(SUM(durata), 0), COALESCE(SUM(costo), 0)
                FROM telefonata
                """
            )
            count, duration, cost = cursor.fetchone()
            self.stdout.write(f"- numero: {count:,}".replace(",", "."))
            self.stdout.write(f"- durata totale: {duration:,} secondi".replace(",", "."))
            self.stdout.write(f"- addebito totale: {cost}")
            if count != EXPECTED_COUNTS["telefonata"]:
                errors.append("Il conteggio aggregato delle telefonate non coincide.")
            if duration != EXPECTED_TOTAL_DURATION:
                errors.append("La durata totale delle telefonate non coincide.")
            if cost != EXPECTED_TOTAL_COST:
                errors.append("L'addebito totale delle telefonate non coincide.")

            cursor.execute(
                """
                SELECT "totaleTelefonate", "durataTotale", "addebitoTotale"
                FROM statistiche_telefonate
                WHERE id = 1
                """
            )
            statistics = cursor.fetchone()
            expected_statistics = (
                EXPECTED_COUNTS["telefonata"],
                EXPECTED_TOTAL_DURATION,
                EXPECTED_TOTAL_COST,
            )
            if statistics != expected_statistics:
                errors.append(
                    "La riga di StatisticheTelefonate non coincide con le aggregazioni reali."
                )

            self.stdout.write("\nControllo della disgiunzione dei codici SIM:")
            cursor.execute(
                """
                SELECT COUNT(*)
                FROM (
                    SELECT codice FROM sim_attiva
                    INTERSECT
                    SELECT codice FROM sim_disattiva
                    UNION ALL
                    SELECT codice FROM sim_attiva
                    INTERSECT
                    SELECT codice FROM sim_non_attiva
                    UNION ALL
                    SELECT codice FROM sim_disattiva
                    INTERSECT
                    SELECT codice FROM sim_non_attiva
                ) AS duplicati
                """
            )
            duplicated_codes = cursor.fetchone()[0]
            self.stdout.write(f"- codici presenti in più stati: {duplicated_codes}")
            if duplicated_codes != 0:
                errors.append("Esistono codici SIM presenti in più tabelle di stato.")

            self.stdout.write("\nControllo delle statistiche per contratto:")
            cursor.execute(
                """
                SELECT
                    COALESCE(SUM("numeroTelefonate"), 0),
                    COALESCE(SUM("durataTotale"), 0),
                    COALESCE(SUM("addebitoTotale"), 0)
                FROM statistiche_contratto
                """
            )
            contract_statistics = cursor.fetchone()
            if contract_statistics != expected_statistics:
                errors.append(
                    "La somma di StatisticheContratto non coincide con le statistiche globali."
                )
            self.stdout.write(
                "- somma chiamate: "
                f"{contract_statistics[0]:,}".replace(",", ".")
            )
            self.stdout.write(
                "- somma durata: "
                f"{contract_statistics[1]:,}".replace(",", ".")
            )
            self.stdout.write(f"- somma addebiti: {contract_statistics[2]}")

        if errors:
            self.stderr.write("")
            for error in errors:
                self.stderr.write(self.style.ERROR(f"- {error}"))
            raise CommandError("La verifica del database non è stata superata.")

        self.stdout.write("")
        self.stdout.write(self.style.SUCCESS("Verifica completata: database coerente."))
