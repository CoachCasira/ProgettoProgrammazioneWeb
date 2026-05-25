from django.urls import path
from . import views

urlpatterns = [
    path('', views.home, name='home'),
    path('contratti/', views.lista_contratti, name='lista_contratti'),
    path('telefonate/', views.lista_telefonate, name='lista_telefonate'),
    path('sim/', views.gestione_sim, name='gestione_sim'), # <-- AGGIUNTA QUESTA RIGA!
]