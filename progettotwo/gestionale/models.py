from django.db import models

class ContrattoTelefonico(models.Model):
    TIPOLOGIA_CHOICES = [
        ('consumo', 'A consumo'),
        ('ricarica', 'Ricaricabile'),
    ]
    numero = models.CharField(max_length=20, primary_key=True)
    dataAttivazione = models.DateField()
    tipo = models.CharField(max_length=20, choices=TIPOLOGIA_CHOICES)
    minutiResidui = models.IntegerField(default=0)
    creditoResiduo = models.DecimalField(max_digits=10, decimal_places=2, default=0.00)

    class Meta:
        db_table = 'ContrattoTelefonico'

    def __str__(self):
        return f"{self.numero} ({self.tipo})"


class Telefonata(models.Model):
    data = models.DateField()
    ora = models.TimeField()
    durata = models.IntegerField(help_text="Durata in secondi")
    costo = models.DecimalField(max_digits=10, decimal_places=2)
    # Questa è la chiave esterna (Foreign Key) che collega la chiamata al numero!
    effettuataDa = models.ForeignKey(ContrattoTelefonico, on_delete=models.CASCADE, db_column='effettuataDa')

    class Meta:
        db_table = 'Telefonata'

    def __str__(self):
        return f"Chiamata da {self.effettuataDa.numero} il {self.data}"


class SIMAttiva(models.Model):
    FORMATO_CHOICES = [
        ('Nano', 'Nano SIM'),
        ('Micro', 'Micro SIM'),
        ('Standard', 'Standard SIM'),
        ('eSIM', 'Virtuale eSIM'),
    ]
    codice = models.CharField(max_length=50, primary_key=True)
    tipoSIM = models.CharField(max_length=20, choices=FORMATO_CHOICES)
    dataAttivazione = models.DateField()
    # Il collegamento OneToOne garantisce che un numero abbia una sola SIM attiva
    associataA = models.OneToOneField(ContrattoTelefonico, on_delete=models.CASCADE, db_column='associataA')

    class Meta:
        db_table = 'SIMAttiva'

    def __str__(self):
        return self.codice


class SIMNonAttiva(models.Model):
    FORMATO_CHOICES = [
        ('Nano', 'Nano SIM'),
        ('Micro', 'Micro SIM'),
        ('Standard', 'Standard SIM'),
        ('eSIM', 'Virtuale eSIM'),
    ]
    codice = models.CharField(max_length=50, primary_key=True)
    tipoSIM = models.CharField(max_length=20, choices=FORMATO_CHOICES)

    class Meta:
        db_table = 'SIMNonAttiva'

    def __str__(self):
        return self.codice


class SIMDisattiva(models.Model):
    FORMATO_CHOICES = [
        ('Nano', 'Nano SIM'),
        ('Micro', 'Micro SIM'),
        ('Standard', 'Standard SIM'),
        ('eSIM', 'Virtuale eSIM'),
    ]
    codice = models.CharField(max_length=50, primary_key=True)
    tipoSIM = models.CharField(max_length=20, choices=FORMATO_CHOICES)
    eraAssociataA = models.CharField(max_length=20) 
    dataAttivazione = models.DateField()
    dataDisattivazione = models.DateField()

    class Meta:
        db_table = 'SIMDisattiva'

    def __str__(self):
        return self.codice