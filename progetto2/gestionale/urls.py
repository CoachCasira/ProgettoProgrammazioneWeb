from django.urls import path

from . import views

urlpatterns = [
    path("", views.home, name="home"),
    path("numeri-telefonici/", views.lista_contratti, name="lista_contratti"),
    path("chiamate/", views.lista_telefonate, name="lista_telefonate"),
    path("sim/", views.gestione_sim, name="gestione_sim"),
    path("sim/nuova-disattivazione/", views.sim_create, name="sim_create"),
    path("sim/<str:codice>/modifica/", views.sim_edit, name="sim_edit"),
    path("sim/<str:codice>/elimina/", views.sim_delete, name="sim_delete"),
    path("api/sim/", views.sim_lookup, name="sim_lookup"),
    path("api/numero/", views.numero_lookup, name="numero_lookup"),
]
