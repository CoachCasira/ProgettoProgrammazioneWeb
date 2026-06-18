"""Modelli del dominio telefonico convertiti per PostgreSQL.

I nomi degli attributi principali restano coerenti con lo schema del primo
progetto, così la conversione dei dati e il confronto con MySQL rimangono
trasparenti. I nomi fisici delle tabelle sono invece minuscoli e adatti a
PostgreSQL.
"""

from __future__ import annotations

from django.core.exceptions import ValidationError
from django.db import models
from django.db.models import F, Q


class ContrattoTelefonico(models.Model):
    TIPO_CONSUMO = "consumo"
    TIPO_RICARICA = "ricarica"
    TIPOLOGIA_CHOICES = [
        (TIPO_CONSUMO, "A consumo"),
        (TIPO_RICARICA, "Ricaricabile"),
    ]

    numero = models.CharField(max_length=20, primary_key=True)
    dataAttivazione = models.DateField(db_column="dataAttivazione")
    tipo = models.CharField(max_length=10, choices=TIPOLOGIA_CHOICES)
    minutiResidui = models.IntegerField(
        db_column="minutiResidui", null=True, blank=True
    )
    creditoResiduo = models.DecimalField(
        db_column="creditoResiduo",
        max_digits=10,
        decimal_places=2,
        null=True,
        blank=True,
    )

    class Meta:
        db_table = "contratto_telefonico"
        ordering = ["numero"]
        indexes = [
            models.Index(fields=["tipo"], name="contratto_tipo_idx"),
            models.Index(
                fields=["dataAttivazione"], name="contratto_data_att_idx"
            ),
        ]
        constraints = [
            models.CheckConstraint(
                name="contratto_residuo_coerente_ck",
                condition=(
                    Q(
                        tipo="consumo",
                        minutiResidui__isnull=False,
                        minutiResidui__gte=0,
                        creditoResiduo__isnull=True,
                    )
                    | Q(
                        tipo="ricarica",
                        minutiResidui__isnull=True,
                        creditoResiduo__isnull=False,
                        creditoResiduo__gte=0,
                    )
                ),
            ),
        ]

    def __str__(self) -> str:
        return f"{self.numero} ({self.get_tipo_display()})"


class Telefonata(models.Model):
    id = models.BigAutoField(primary_key=True)
    effettuataDa = models.ForeignKey(
        ContrattoTelefonico,
        db_column="effettuataDa",
        on_delete=models.PROTECT,
        related_name="telefonate",
    )
    data = models.DateField()
    ora = models.TimeField()
    durata = models.IntegerField(help_text="Durata in secondi")
    costo = models.DecimalField(max_digits=10, decimal_places=2)

    class Meta:
        db_table = "telefonata"
        ordering = ["-data", "-ora", "-id"]
        indexes = [
            models.Index(fields=["data"], name="tel_data_idx"),
            models.Index(
                fields=["effettuataDa", "data", "ora"],
                name="tel_num_data_ora_idx",
            ),
            models.Index(fields=["data", "ora"], name="tel_data_ora_idx"),
            models.Index(fields=["durata"], name="tel_durata_idx"),
            models.Index(fields=["costo"], name="tel_costo_idx"),
            models.Index(fields=["ora", "durata"], name="tel_ora_durata_idx"),
            models.Index(
                fields=["durata", "-data", "-ora", "-id"],
                name="tel_dur_data_ord_idx",
            ),
            models.Index(
                fields=["durata", "ora", "-data", "-id"],
                name="tel_dur_ora_ord_idx",
            ),
            models.Index(
                fields=["ora", "-data", "-id"], name="tel_ora_data_ord_idx"
            ),
        ]
        constraints = [
            models.CheckConstraint(
                name="telefonata_durata_nonneg_ck",
                condition=Q(durata__gte=0),
            ),
            models.CheckConstraint(
                name="telefonata_costo_nonneg_ck",
                condition=Q(costo__gte=0),
            ),
        ]

    def clean(self) -> None:
        super().clean()
        if self.effettuataDa_id and self.data:
            contratto = self.effettuataDa
            if self.data < contratto.dataAttivazione:
                raise ValidationError(
                    {"data": "La chiamata non può precedere l'attivazione del numero."}
                )

    def __str__(self) -> str:
        return f"Chiamata da {self.effettuataDa_id} del {self.data}"


class SIMBase(models.Model):
    FORMATO_CHOICES = [
        ("Nano", "Nano SIM"),
        ("Micro", "Micro SIM"),
        ("Standard", "Standard SIM"),
        ("eSIM", "Virtuale eSIM"),
    ]

    codice = models.CharField(max_length=30, primary_key=True)
    tipoSIM = models.CharField(
        db_column="tipoSIM", max_length=20, choices=FORMATO_CHOICES
    )

    class Meta:
        abstract = True

    def _validate_disjoint_code(self) -> None:
        """Impedisce il riuso del codice in un'altra tabella di stato SIM.

        Il controllo applicativo completa i vincoli delle singole tabelle,
        poiché PostgreSQL non può esprimere un vincolo UNIQUE tra tre tabelle.
        """

        other_models = (SIMAttiva, SIMNonAttiva, SIMDisattiva)
        for model in other_models:
            if model is self.__class__:
                continue
            if model.objects.filter(pk=self.codice).exists():
                raise ValidationError(
                    {"codice": "Il codice SIM è già presente in un altro stato."}
                )

    def clean(self) -> None:
        super().clean()
        if self.codice:
            self._validate_disjoint_code()

    def __str__(self) -> str:
        return self.codice


class SIMAttiva(SIMBase):
    associataA = models.OneToOneField(
        ContrattoTelefonico,
        db_column="associataA",
        on_delete=models.PROTECT,
        related_name="sim_attiva",
    )
    dataAttivazione = models.DateField(db_column="dataAttivazione")

    class Meta:
        db_table = "sim_attiva"
        ordering = ["codice"]
        indexes = [
            models.Index(
                fields=["dataAttivazione"], name="sim_attiva_data_idx"
            ),
        ]

    def clean(self) -> None:
        super().clean()
        if self.associataA_id and self.dataAttivazione:
            contratto = self.associataA
            if self.dataAttivazione < contratto.dataAttivazione:
                raise ValidationError(
                    {
                        "dataAttivazione": (
                            "La SIM non può essere attivata prima del numero telefonico."
                        )
                    }
                )


class SIMNonAttiva(SIMBase):
    class Meta:
        db_table = "sim_non_attiva"
        ordering = ["codice"]
        indexes = [
            models.Index(fields=["tipoSIM"], name="sim_nonatt_tipo_idx"),
        ]


class SIMDisattiva(SIMBase):
    eraAssociataA = models.ForeignKey(
        ContrattoTelefonico,
        db_column="eraAssociataA",
        on_delete=models.PROTECT,
        related_name="sim_disattive",
    )
    dataAttivazione = models.DateField(db_column="dataAttivazione")
    dataDisattivazione = models.DateField(db_column="dataDisattivazione")

    class Meta:
        db_table = "sim_disattiva"
        ordering = ["-dataDisattivazione", "codice"]
        indexes = [
            models.Index(
                fields=["eraAssociataA", "dataDisattivazione"],
                name="sim_dis_num_data_idx",
            ),
            models.Index(
                fields=["dataDisattivazione"], name="sim_dis_data_idx"
            ),
        ]
        constraints = [
            models.CheckConstraint(
                name="sim_dis_date_coerenti_ck",
                condition=Q(dataDisattivazione__gte=F("dataAttivazione")),
            ),
        ]

    def clean(self) -> None:
        super().clean()
        if self.dataAttivazione and self.dataDisattivazione:
            if self.dataDisattivazione < self.dataAttivazione:
                raise ValidationError(
                    {
                        "dataDisattivazione": (
                            "La disattivazione non può precedere l'attivazione."
                        )
                    }
                )
        if self.eraAssociataA_id and self.dataAttivazione:
            contratto = self.eraAssociataA
            if self.dataAttivazione < contratto.dataAttivazione:
                raise ValidationError(
                    {
                        "dataAttivazione": (
                            "La SIM non può essere attivata prima del numero telefonico."
                        )
                    }
                )


class StatisticheContratto(models.Model):
    numero = models.OneToOneField(
        ContrattoTelefonico,
        primary_key=True,
        db_column="numero",
        on_delete=models.CASCADE,
        related_name="statistiche",
    )
    numeroTelefonate = models.BigIntegerField(
        db_column="numeroTelefonate", default=0
    )
    durataTotale = models.BigIntegerField(db_column="durataTotale", default=0)
    addebitoTotale = models.DecimalField(
        db_column="addebitoTotale", max_digits=18, decimal_places=2, default=0
    )
    ultimaTelefonata = models.DateField(
        db_column="ultimaTelefonata", null=True, blank=True
    )

    class Meta:
        db_table = "statistiche_contratto"
        constraints = [
            models.CheckConstraint(
                name="stat_contr_num_nonneg_ck",
                condition=Q(numeroTelefonate__gte=0),
            ),
            models.CheckConstraint(
                name="stat_contr_dur_nonneg_ck",
                condition=Q(durataTotale__gte=0),
            ),
            models.CheckConstraint(
                name="stat_contr_add_nonneg_ck",
                condition=Q(addebitoTotale__gte=0),
            ),
        ]

    def __str__(self) -> str:
        return f"Statistiche {self.numero_id}"


class StatisticheSIM(models.Model):
    STATO_CHOICES = [
        ("attive", "SIM in uso"),
        ("disponibili", "SIM disponibili"),
        ("disattive", "SIM disattivate"),
    ]

    codice = models.CharField(max_length=30)
    stato = models.CharField(max_length=16, choices=STATO_CHOICES)
    numeroChiamate = models.BigIntegerField(db_column="numeroChiamate", default=0)

    class Meta:
        db_table = "statistiche_sim"
        constraints = [
            models.UniqueConstraint(
                fields=["codice", "stato"], name="stat_sim_cod_stato_uq"
            ),
            models.CheckConstraint(
                name="stat_sim_num_nonneg_ck",
                condition=Q(numeroChiamate__gte=0),
            ),
        ]
        indexes = [
            models.Index(
                fields=["stato", "-numeroChiamate", "codice"],
                name="stat_sim_ordine_idx",
            )
        ]

    def __str__(self) -> str:
        return f"{self.codice} - {self.get_stato_display()}"


class StatisticheTelefonate(models.Model):
    id = models.PositiveSmallIntegerField(primary_key=True, default=1, editable=False)
    totaleTelefonate = models.BigIntegerField(
        db_column="totaleTelefonate", default=0
    )
    durataTotale = models.BigIntegerField(db_column="durataTotale", default=0)
    durataMedia = models.DecimalField(
        db_column="durataMedia", max_digits=14, decimal_places=2, default=0
    )
    addebitoTotale = models.DecimalField(
        db_column="addebitoTotale", max_digits=20, decimal_places=2, default=0
    )
    aggiornatoIl = models.DateTimeField(db_column="aggiornatoIl")

    class Meta:
        db_table = "statistiche_telefonate"
        constraints = [
            models.CheckConstraint(
                name="stat_tel_singleton_ck", condition=Q(id=1)
            ),
            models.CheckConstraint(
                name="stat_tel_tot_nonneg_ck",
                condition=Q(totaleTelefonate__gte=0),
            ),
            models.CheckConstraint(
                name="stat_tel_dur_nonneg_ck",
                condition=Q(durataTotale__gte=0),
            ),
            models.CheckConstraint(
                name="stat_tel_media_nonneg_ck",
                condition=Q(durataMedia__gte=0),
            ),
            models.CheckConstraint(
                name="stat_tel_add_nonneg_ck",
                condition=Q(addebitoTotale__gte=0),
            ),
        ]

    def __str__(self) -> str:
        return "Statistiche globali delle telefonate"
