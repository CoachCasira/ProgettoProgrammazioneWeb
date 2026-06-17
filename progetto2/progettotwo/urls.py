"""URL principali dell'applicazione."""

from django.urls import include, path

urlpatterns = [
    path("", include("gestionale.urls")),
]
