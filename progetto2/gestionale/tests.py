from datetime import date, time, timedelta
from decimal import Decimal

from django.db import connection
from django.test import TestCase
from django.test.utils import CaptureQueriesContext
from django.urls import reverse
from django.utils import timezone

from .models import (
    ContrattoTelefonico,
    SIMAttiva,
    SIMDisattiva,
    SIMNonAttiva,
    StatisticheContratto,
    StatisticheSIM,
    StatisticheTelefonate,
    Telefonata,
)


class GestioneTelefonicaTests(TestCase):
    @classmethod
    def setUpTestData(cls):
        cls.contract = ContrattoTelefonico.objects.create(
            numero="3401234567",
            dataAttivazione=date(2025, 1, 1),
            tipo="ricarica",
            minutiResidui=None,
            creditoResiduo=Decimal("15.00"),
        )
        cls.sim = SIMAttiva.objects.create(
            codice="8939470000000000001",
            tipoSIM="Nano",
            associataA=cls.contract,
            dataAttivazione=date(2025, 1, 1),
        )
        Telefonata.objects.create(
            effettuataDa=cls.contract,
            data=date(2025, 2, 1),
            ora=time(10, 30),
            durata=90,
            costo=Decimal("0.25"),
        )
        StatisticheContratto.objects.create(
            numero=cls.contract,
            numeroTelefonate=1,
            durataTotale=90,
            addebitoTotale=Decimal("0.25"),
            ultimaTelefonata=date(2025, 2, 1),
        )
        StatisticheSIM.objects.create(
            codice=cls.sim.codice,
            stato="attive",
            numeroChiamate=1,
        )
        StatisticheTelefonate.objects.create(
            id=1,
            totaleTelefonate=1,
            durataTotale=90,
            durataMedia=Decimal("90"),
            addebitoTotale=Decimal("0.25"),
            aggiornatoIl=timezone.now(),
        )

    def test_pagine_principali(self):
        for name in ("home", "lista_contratti", "lista_telefonate", "gestione_sim"):
            response = self.client.get(reverse(name))
            self.assertEqual(response.status_code, 200)

    def test_filtri_collegati(self):
        response = self.client.get(reverse("lista_contratti"), {"numero": "340", "ordine": "piu_chiamate"})
        self.assertContains(response, self.contract.numero)
        response = self.client.get(reverse("lista_telefonate"), {"contratto": self.contract.numero})
        self.assertContains(response, self.contract.numero)
        response = self.client.get(reverse("gestione_sim"), {"sim_states": "attive", "codice": "8939"})
        self.assertContains(response, self.sim.codice)

    def test_esportazioni_excel(self):
        for name in ("lista_contratti", "lista_telefonate", "gestione_sim"):
            response = self.client.get(reverse(name), {"export": "excel"})
            self.assertEqual(response.status_code, 200)
            self.assertTrue(response["Content-Type"].startswith("application/vnd.ms-excel"))

    def test_crud_sim_disattivata(self):
        response = self.client.post(
            reverse("sim_create"),
            {
                "codice": self.sim.codice,
                "tipoSIM": self.sim.tipoSIM,
                "eraAssociataA": self.contract.numero,
                "dataAttivazione": "2025-01-01",
                "dataDisattivazione": "2025-02-02",
            },
        )
        self.assertEqual(response.status_code, 302)
        self.assertFalse(SIMAttiva.objects.filter(pk=self.sim.codice).exists())
        self.assertTrue(SIMDisattiva.objects.filter(pk=self.sim.codice).exists())

        response = self.client.post(
            reverse("sim_edit", args=[self.sim.codice]),
            {
                "codice": self.sim.codice,
                "tipoSIM": "eSIM",
                "eraAssociataA": self.contract.numero,
                "dataAttivazione": "2025-01-01",
                "dataDisattivazione": "2025-02-03",
            },
        )
        self.assertEqual(response.status_code, 302)
        self.assertEqual(SIMDisattiva.objects.get(pk=self.sim.codice).tipoSIM, "eSIM")

        response = self.client.post(reverse("sim_delete", args=[self.sim.codice]))
        self.assertEqual(response.status_code, 302)
        self.assertFalse(SIMDisattiva.objects.filter(pk=self.sim.codice).exists())


class RegressioniInterfacciaTests(TestCase):
    """Copre i problemi emersi durante il collaudo del Progetto 2."""

    @classmethod
    def setUpTestData(cls):
        cls.contract = ContrattoTelefonico.objects.create(
            numero="3400000001",
            dataAttivazione=date(2025, 1, 1),
            tipo="ricarica",
            minutiResidui=None,
            creditoResiduo=Decimal("20.00"),
        )
        cls.active_sim = SIMAttiva.objects.create(
            codice="8939470000000000101",
            tipoSIM="Nano",
            associataA=cls.contract,
            dataAttivazione=date(2025, 1, 1),
        )
        cls.available_sim = SIMNonAttiva.objects.create(
            codice="8939470000000000102",
            tipoSIM="eSIM",
        )
        cls.disabled_contract = ContrattoTelefonico.objects.create(
            numero="3400000002",
            dataAttivazione=date(2024, 1, 1),
            tipo="consumo",
            minutiResidui=120,
            creditoResiduo=None,
        )
        cls.disabled_sim = SIMDisattiva.objects.create(
            codice="8939470000000000103",
            tipoSIM="Micro",
            eraAssociataA=cls.disabled_contract,
            dataAttivazione=date(2024, 1, 1),
            dataDisattivazione=date(2025, 1, 15),
        )

        calls = []
        for index in range(18):
            calls.append(
                Telefonata(
                    effettuataDa=cls.contract,
                    data=date(2025, 2, 1) + timedelta(days=index),
                    ora=time(10, index % 60),
                    durata=60 + index,
                    costo=Decimal("0.10") + Decimal(index) / Decimal("100"),
                )
            )
        Telefonata.objects.bulk_create(calls)
        StatisticheContratto.objects.create(
            numero=cls.contract,
            numeroTelefonate=18,
            durataTotale=sum(60 + index for index in range(18)),
            addebitoTotale=sum(
                (Decimal("0.10") + Decimal(index) / Decimal("100") for index in range(18)),
                Decimal("0"),
            ),
            ultimaTelefonata=date(2025, 2, 18),
        )
        StatisticheContratto.objects.create(
            numero=cls.disabled_contract,
            numeroTelefonate=0,
            durataTotale=0,
            addebitoTotale=Decimal("0"),
            ultimaTelefonata=None,
        )
        StatisticheSIM.objects.create(codice=cls.active_sim.codice, stato="attive", numeroChiamate=18)
        StatisticheSIM.objects.create(codice=cls.available_sim.codice, stato="disponibili", numeroChiamate=0)
        StatisticheSIM.objects.create(codice=cls.disabled_sim.codice, stato="disattive", numeroChiamate=0)
        StatisticheTelefonate.objects.create(
            id=1,
            totaleTelefonate=18,
            durataTotale=sum(60 + index for index in range(18)),
            durataMedia=Decimal("68.5"),
            addebitoTotale=Decimal("3.33"),
            aggiornatoIl=timezone.now(),
        )

    def test_filtri_aggiornano_solo_i_risultati(self):
        expected = {
            "lista_contratti": '#contratti-results',
            "lista_telefonate": '#telefonate-results',
            "gestione_sim": '#sim-results',
        }
        for name, target in expected.items():
            response = self.client.get(reverse(name))
            self.assertEqual(response.status_code, 200)
            self.assertContains(response, f'data-update-target="{target}"')
            self.assertNotContains(response, 'data-update-target=".content"', html=False)

    def test_vista_tabellare_e_navigazione_sono_presenti(self):
        for name in ("lista_contratti", "lista_telefonate", "gestione_sim"):
            response = self.client.get(reverse(name))
            self.assertContains(response, 'data-view-panel="table"')
            self.assertContains(response, 'data-lazy-list="table"')
            self.assertContains(response, 'data-results-first="true"')
            self.assertContains(response, 'data-results-last="true"')
            self.assertNotContains(response, 'view-panel view-panel-table is-hidden')

    def test_righe_tabellari_ajax_non_sono_vuote(self):
        requests = (
            ("lista_contratti", {"ajax_rows": "1", "limit": "12"}, self.contract.numero),
            ("lista_telefonate", {"ajax_rows": "1", "limit": "12"}, self.contract.numero),
            ("gestione_sim", {"ajax_rows": "1", "limit": "12", "sim_states[]": "attive"}, self.active_sim.codice),
        )
        for name, params, expected_text in requests:
            response = self.client.get(reverse(name), params, HTTP_X_REQUESTED_WITH="XMLHttpRequest")
            self.assertEqual(response.status_code, 200)
            payload = response.json()
            self.assertIn("table_html", payload)
            self.assertIn(expected_text, payload["table_html"])

    def test_ricerca_rapida_usa_le_modali_del_progetto_uno(self):
        response = self.client.get(reverse("home"), {"ricerca_globale": "3400000001"})
        self.assertContains(response, 'data-phone-card-modal="true"')
        self.assertContains(response, f'data-phone-number="{self.contract.numero}"')

        response = self.client.get(reverse("home"), {"ricerca_globale": self.active_sim.codice})
        self.assertContains(response, 'data-sim-card-modal="true"')
        self.assertContains(response, f'data-sim-code="{self.active_sim.codice}"')

    def test_tabella_sim_mantiene_colonne_complete_con_ogni_filtro(self):
        for state, expected_code in (
            ("attive", self.active_sim.codice),
            ("disponibili", self.available_sim.codice),
            ("disattive", self.disabled_sim.codice),
        ):
            response = self.client.get(
                reverse("gestione_sim"),
                [("sim_states[]", state)],
            )
            self.assertEqual(response.status_code, 200)
            self.assertContains(response, 'class="data-table sim-data-table sim-table-state-tutte"')
            self.assertContains(response, ">Stato<", html=False)
            self.assertContains(response, ">Data disattivazione<", html=False)
            self.assertContains(response, ">Azioni<", html=False)
            self.assertContains(response, expected_code)
            self.assertNotContains(response, 'class="sim-code-actions"')

        active_response = self.client.get(
            reverse("gestione_sim"),
            [("sim_states[]", "attive")],
        )
        active_html = active_response.content.decode("utf-8")
        code_position = active_html.index(self.active_sim.codice)
        action_position = active_html.index("Disattiva SIM", code_position)
        self.assertGreater(action_position, code_position)

    def test_filtro_sim_accetta_il_nome_array_del_form(self):
        response = self.client.get(
            reverse("gestione_sim"),
            [("sim_states[]", "disponibili")],
        )
        self.assertContains(response, self.available_sim.codice)
        self.assertNotContains(response, self.active_sim.codice)

    def test_ultimo_blocco_chiamate_non_usa_un_offset_enorme(self):
        with CaptureQueriesContext(connection) as captured:
            response = self.client.get(
                reverse("lista_telefonate"),
                {"ajax_rows": "1", "jump_last": "1", "limit": "3"},
                HTTP_X_REQUESTED_WITH="XMLHttpRequest",
            )
        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertTrue(payload["from_end"])
        self.assertEqual(payload["has_more"], False)
        self.assertIn(self.contract.numero, payload["table_html"])
        call_queries = [query["sql"] for query in captured.captured_queries if 'telefonata' in query["sql"].lower()]
        self.assertTrue(call_queries)
        self.assertFalse(any(" OFFSET " in sql.upper() for sql in call_queries))


    def test_tutti_gli_ordinamenti_principali_rispondono_senza_errori(self):
        contract_orders = (
            "recenti", "disattivati_recenti", "chiamate_crescenti",
            "piu_chiamate", "maggiore_durata", "maggiore_spesa",
        )
        for order in contract_orders:
            response = self.client.get(
                reverse("lista_contratti"),
                {"ordine": order},
                HTTP_X_REQUESTED_WITH="XMLHttpRequest",
            )
            self.assertEqual(response.status_code, 200, order)
            self.assertContains(response, 'id="contratti-results"')

        call_orders = (
            "recenti", "meno_recenti", "durata_desc",
            "durata_asc", "costo_desc", "costo_asc",
        )
        for order in call_orders:
            response = self.client.get(
                reverse("lista_telefonate"),
                {"ordine": order, "ajax_rows": "1", "limit": "12"},
                HTTP_X_REQUESTED_WITH="XMLHttpRequest",
            )
            self.assertEqual(response.status_code, 200, order)
            self.assertIn(self.contract.numero, response.json()["table_html"])

        sim_orders = ("nessuno", "piu_chiamate", "attivate_recenti", "disattivate_recenti")
        for order in sim_orders:
            response = self.client.get(
                reverse("gestione_sim"),
                {"ordine_sim": order, "ajax_rows": "1", "limit": "12"},
                HTTP_X_REQUESTED_WITH="XMLHttpRequest",
            )
            self.assertEqual(response.status_code, 200, order)
            self.assertIn("table_html", response.json())

    def test_crud_sim_ripristina_attributi_e_layout_finali(self):
        response = self.client.get(reverse("sim_create"), {"codice": self.active_sim.codice})
        self.assertContains(response, 'class="crud-form"')
        self.assertContains(response, 'data-sim-crud-form="true"')
        self.assertContains(response, 'data-sim-code-lookup="true"')
        self.assertContains(response, 'data-phone-lookup="true"')
        self.assertContains(response, 'data-deactivation-date="true"')
        self.assertContains(response, 'form-row')
