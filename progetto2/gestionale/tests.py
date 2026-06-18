from datetime import date, time
from decimal import Decimal

from django.test import TestCase
from django.urls import reverse
from django.utils import timezone

from .models import (
    ContrattoTelefonico,
    SIMAttiva,
    SIMDisattiva,
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
