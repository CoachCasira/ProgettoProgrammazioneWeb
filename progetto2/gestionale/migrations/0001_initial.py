# Generated for Django 5.2 and PostgreSQL.

import django.db.models.deletion
from django.db import migrations, models


class Migration(migrations.Migration):
    initial = True
    dependencies = []

    operations = [
        migrations.CreateModel(
            name="ContrattoTelefonico",
            fields=[
                ("numero", models.CharField(max_length=20, primary_key=True, serialize=False)),
                ("dataAttivazione", models.DateField(db_column="dataAttivazione")),
                ("tipo", models.CharField(choices=[("consumo", "A consumo"), ("ricarica", "Ricaricabile")], max_length=10)),
                ("minutiResidui", models.IntegerField(blank=True, db_column="minutiResidui", null=True)),
                ("creditoResiduo", models.DecimalField(blank=True, db_column="creditoResiduo", decimal_places=2, max_digits=10, null=True)),
            ],
            options={
                "db_table": "contratto_telefonico",
                "ordering": ["numero"],
                "indexes": [
                    models.Index(fields=["tipo"], name="contratto_tipo_idx"),
                    models.Index(fields=["dataAttivazione"], name="contratto_data_att_idx"),
                ],
                "constraints": [
                    models.CheckConstraint(
                        condition=(
                            models.Q(creditoResiduo__isnull=True, minutiResidui__gte=0, minutiResidui__isnull=False, tipo="consumo")
                            | models.Q(creditoResiduo__gte=0, creditoResiduo__isnull=False, minutiResidui__isnull=True, tipo="ricarica")
                        ),
                        name="contratto_residuo_coerente_ck",
                    )
                ],
            },
        ),
        migrations.CreateModel(
            name="SIMNonAttiva",
            fields=[
                ("codice", models.CharField(max_length=30, primary_key=True, serialize=False)),
                ("tipoSIM", models.CharField(choices=[("Nano", "Nano SIM"), ("Micro", "Micro SIM"), ("Standard", "Standard SIM"), ("eSIM", "Virtuale eSIM")], db_column="tipoSIM", max_length=20)),
            ],
            options={
                "db_table": "sim_non_attiva",
                "ordering": ["codice"],
                "indexes": [models.Index(fields=["tipoSIM"], name="sim_nonatt_tipo_idx")],
            },
        ),
        migrations.CreateModel(
            name="StatisticheSIM",
            fields=[
                ("id", models.BigAutoField(auto_created=True, primary_key=True, serialize=False, verbose_name="ID")),
                ("codice", models.CharField(max_length=30)),
                ("stato", models.CharField(choices=[("attive", "SIM in uso"), ("disponibili", "SIM disponibili"), ("disattive", "SIM disattivate")], max_length=16)),
                ("numeroChiamate", models.BigIntegerField(db_column="numeroChiamate", default=0)),
            ],
            options={
                "db_table": "statistiche_sim",
                "indexes": [models.Index(fields=["stato", "-numeroChiamate", "codice"], name="stat_sim_ordine_idx")],
                "constraints": [
                    models.UniqueConstraint(fields=("codice", "stato"), name="stat_sim_cod_stato_uq"),
                    models.CheckConstraint(condition=models.Q(numeroChiamate__gte=0), name="stat_sim_num_nonneg_ck"),
                ],
            },
        ),
        migrations.CreateModel(
            name="StatisticheTelefonate",
            fields=[
                ("id", models.PositiveSmallIntegerField(default=1, editable=False, primary_key=True, serialize=False)),
                ("totaleTelefonate", models.BigIntegerField(db_column="totaleTelefonate", default=0)),
                ("durataTotale", models.BigIntegerField(db_column="durataTotale", default=0)),
                ("durataMedia", models.DecimalField(db_column="durataMedia", decimal_places=2, default=0, max_digits=14)),
                ("addebitoTotale", models.DecimalField(db_column="addebitoTotale", decimal_places=2, default=0, max_digits=20)),
                ("aggiornatoIl", models.DateTimeField(db_column="aggiornatoIl")),
            ],
            options={
                "db_table": "statistiche_telefonate",
                "constraints": [
                    models.CheckConstraint(condition=models.Q(id=1), name="stat_tel_singleton_ck"),
                    models.CheckConstraint(condition=models.Q(totaleTelefonate__gte=0), name="stat_tel_tot_nonneg_ck"),
                    models.CheckConstraint(condition=models.Q(durataTotale__gte=0), name="stat_tel_dur_nonneg_ck"),
                    models.CheckConstraint(condition=models.Q(durataMedia__gte=0), name="stat_tel_media_nonneg_ck"),
                    models.CheckConstraint(condition=models.Q(addebitoTotale__gte=0), name="stat_tel_add_nonneg_ck"),
                ],
            },
        ),
        migrations.CreateModel(
            name="SIMAttiva",
            fields=[
                ("codice", models.CharField(max_length=30, primary_key=True, serialize=False)),
                ("tipoSIM", models.CharField(choices=[("Nano", "Nano SIM"), ("Micro", "Micro SIM"), ("Standard", "Standard SIM"), ("eSIM", "Virtuale eSIM")], db_column="tipoSIM", max_length=20)),
                ("dataAttivazione", models.DateField(db_column="dataAttivazione")),
                ("associataA", models.OneToOneField(db_column="associataA", on_delete=django.db.models.deletion.PROTECT, related_name="sim_attiva", to="gestionale.contrattotelefonico")),
            ],
            options={
                "db_table": "sim_attiva",
                "ordering": ["codice"],
                "indexes": [models.Index(fields=["dataAttivazione"], name="sim_attiva_data_idx")],
            },
        ),
        migrations.CreateModel(
            name="SIMDisattiva",
            fields=[
                ("codice", models.CharField(max_length=30, primary_key=True, serialize=False)),
                ("tipoSIM", models.CharField(choices=[("Nano", "Nano SIM"), ("Micro", "Micro SIM"), ("Standard", "Standard SIM"), ("eSIM", "Virtuale eSIM")], db_column="tipoSIM", max_length=20)),
                ("dataAttivazione", models.DateField(db_column="dataAttivazione")),
                ("dataDisattivazione", models.DateField(db_column="dataDisattivazione")),
                ("eraAssociataA", models.ForeignKey(db_column="eraAssociataA", on_delete=django.db.models.deletion.PROTECT, related_name="sim_disattive", to="gestionale.contrattotelefonico")),
            ],
            options={
                "db_table": "sim_disattiva",
                "ordering": ["-dataDisattivazione", "codice"],
                "indexes": [
                    models.Index(fields=["eraAssociataA", "dataDisattivazione"], name="sim_dis_num_data_idx"),
                    models.Index(fields=["dataDisattivazione"], name="sim_dis_data_idx"),
                ],
                "constraints": [
                    models.CheckConstraint(condition=models.Q(dataDisattivazione__gte=models.F("dataAttivazione")), name="sim_dis_date_coerenti_ck")
                ],
            },
        ),
        migrations.CreateModel(
            name="StatisticheContratto",
            fields=[
                ("numero", models.OneToOneField(db_column="numero", on_delete=django.db.models.deletion.CASCADE, primary_key=True, related_name="statistiche", serialize=False, to="gestionale.contrattotelefonico")),
                ("numeroTelefonate", models.BigIntegerField(db_column="numeroTelefonate", default=0)),
                ("durataTotale", models.BigIntegerField(db_column="durataTotale", default=0)),
                ("addebitoTotale", models.DecimalField(db_column="addebitoTotale", decimal_places=2, default=0, max_digits=18)),
                ("ultimaTelefonata", models.DateField(blank=True, db_column="ultimaTelefonata", null=True)),
            ],
            options={
                "db_table": "statistiche_contratto",
                "constraints": [
                    models.CheckConstraint(condition=models.Q(numeroTelefonate__gte=0), name="stat_contr_num_nonneg_ck"),
                    models.CheckConstraint(condition=models.Q(durataTotale__gte=0), name="stat_contr_dur_nonneg_ck"),
                    models.CheckConstraint(condition=models.Q(addebitoTotale__gte=0), name="stat_contr_add_nonneg_ck"),
                ],
            },
        ),
        migrations.CreateModel(
            name="Telefonata",
            fields=[
                ("id", models.BigAutoField(primary_key=True, serialize=False)),
                ("data", models.DateField()),
                ("ora", models.TimeField()),
                ("durata", models.IntegerField(help_text="Durata in secondi")),
                ("costo", models.DecimalField(decimal_places=2, max_digits=10)),
                ("effettuataDa", models.ForeignKey(db_column="effettuataDa", on_delete=django.db.models.deletion.PROTECT, related_name="telefonate", to="gestionale.contrattotelefonico")),
            ],
            options={
                "db_table": "telefonata",
                "ordering": ["-data", "-ora", "-id"],
                "indexes": [
                    models.Index(fields=["data"], name="tel_data_idx"),
                    models.Index(fields=["effettuataDa", "data", "ora"], name="tel_num_data_ora_idx"),
                    models.Index(fields=["data", "ora"], name="tel_data_ora_idx"),
                    models.Index(fields=["durata"], name="tel_durata_idx"),
                    models.Index(fields=["costo"], name="tel_costo_idx"),
                    models.Index(fields=["ora", "durata"], name="tel_ora_durata_idx"),
                    models.Index(fields=["durata", "-data", "-ora", "-id"], name="tel_dur_data_ord_idx"),
                    models.Index(fields=["durata", "ora", "-data", "-id"], name="tel_dur_ora_ord_idx"),
                    models.Index(fields=["ora", "-data", "-id"], name="tel_ora_data_ord_idx"),
                ],
                "constraints": [
                    models.CheckConstraint(condition=models.Q(durata__gte=0), name="telefonata_durata_nonneg_ck"),
                    models.CheckConstraint(condition=models.Q(costo__gte=0), name="telefonata_costo_nonneg_ck"),
                ],
            },
        ),
    ]
