from django.contrib import admin
from django.urls import path, include  # <-- Abbiamo aggiunto 'include' qui

urlpatterns = [
    path('admin/', admin.site.urls),
    path('', include('gestionale.urls')), # <-- Questa riga dice a Django di guardare dentro l'app gestionale!
]