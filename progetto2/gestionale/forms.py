from __future__ import annotations

from django import forms

from .models import SIMBase


class SIMDisattivaForm(forms.Form):
    codice = forms.CharField(
        label="Codice SIM",
        max_length=30,
        widget=forms.TextInput(
            attrs={
                "inputmode": "numeric",
                "autocomplete": "off",
                "placeholder": "Inserire il codice SIM",
                "data-clearable": "true",
                "data-sim-code-lookup": "true",
            }
        ),
    )
    tipoSIM = forms.ChoiceField(
        label="Formato SIM",
        choices=[("", "Selezionare il formato")] + SIMBase.FORMATO_CHOICES,
    )
    eraAssociataA = forms.CharField(
        label="Numero precedentemente associato",
        max_length=20,
        widget=forms.TextInput(
            attrs={
                "inputmode": "numeric",
                "autocomplete": "off",
                "placeholder": "Inserire il numero telefonico",
                "data-clearable": "true",
                "data-phone-lookup": "true",
            }
        ),
    )
    dataAttivazione = forms.DateField(
        label="Data di attivazione",
        widget=forms.DateInput(
            format="%Y-%m-%d",
            attrs={
                "type": "date",
                "readonly": "readonly",
                "data-auto-activation-date": "true",
                "data-crud-date-picker": "true",
            },
        ),
        input_formats=["%Y-%m-%d"],
    )
    dataDisattivazione = forms.DateField(
        label="Data di disattivazione",
        widget=forms.DateInput(
            format="%Y-%m-%d",
            attrs={
                "type": "date",
                "data-deactivation-date": "true",
                "data-crud-date-picker": "true",
            },
        ),
        input_formats=["%Y-%m-%d"],
    )

    def clean_codice(self) -> str:
        value = self.cleaned_data["codice"].strip()
        if not value.isdigit():
            raise forms.ValidationError("Il codice SIM può contenere solo cifre.")
        return value

    def clean_eraAssociataA(self) -> str:
        value = self.cleaned_data["eraAssociataA"].strip()
        if not value.isdigit():
            raise forms.ValidationError("Il numero telefonico può contenere solo cifre.")
        return value

    def clean(self):
        cleaned = super().clean()
        start = cleaned.get("dataAttivazione")
        end = cleaned.get("dataDisattivazione")
        if start and end and end < start:
            self.add_error(
                "dataDisattivazione",
                "La disattivazione non può precedere l’attivazione della SIM.",
            )
        return cleaned
