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
                "data-validation": "digits",
                "data-sim-code-lookup": "true",
                "data-required-message": "Inserire il codice della SIM.",
                "data-format-message": "Il codice SIM può contenere solo cifre.",
            }
        ),
    )
    tipoSIM = forms.ChoiceField(
        label="Formato SIM",
        choices=[("", "Seleziona formato")] + SIMBase.FORMATO_CHOICES,
        widget=forms.Select(
            attrs={
                "data-crud-dependent": "true",
                "data-required-message": "Selezionare il formato della SIM.",
            }
        ),
    )
    eraAssociataA = forms.CharField(
        label="Numero di telefono precedentemente associato",
        max_length=20,
        widget=forms.TextInput(
            attrs={
                "inputmode": "numeric",
                "autocomplete": "off",
                "placeholder": "Es. 3401234567",
                "data-clearable": "true",
                "data-crud-dependent": "true",
                "data-validation": "digits",
                "data-phone-lookup": "true",
                "data-required-message": "Inserire il numero di telefono precedentemente associato.",
                "data-format-message": "Il numero di telefono può contenere solo cifre.",
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
                "class": "input-readonly",
                "data-crud-dependent": "true",
                "data-auto-activation-date": "true",
                "data-site-date-picker": "true",
                "aria-label": "Visualizza la data di attivazione",
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
                "data-crud-dependent": "true",
                "data-deactivation-date": "true",
                "data-site-date-picker": "true",
                "data-required-message": "Inserire la data di disattivazione.",
                "aria-label": "Seleziona la data di disattivazione",
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
