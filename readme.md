# Projet Météo Symfony

Affichage comparatif des prévisions horaires de plusieurs fournisseurs météo (Met.no, Open-Meteo, etc.) pour Poitiers.

## Fonctionnalités

- Récupération des données météo auprès de plusieurs APIs
- Affichage des prévisions journalières et horaires
- Graphiques avec température, icônes et ligne de l’heure actuelle
- Filtres interactifs par fournisseur

## Technologies

- Symfony (PHP)
- Chart.js (graphiques)
- Twig (templating)
- HTML/CSS/JS

## Lancer en local

```bash
git clone https://github.com/baudelotphilippe/meteo.git
cd meteo
composer install

cp .env .env.local
# Configurer vos clés API dans .env.local

symfony server:start
```

Vous pouvez personnaliser en surchargeant les valeurs ci dessous dans le env.local : 


OPENWEATHER_API_KEY=xxxxxxxxx
WEATHERAPI_KEY=xxxxxxxxx
METEO_NAME=Poitiers
METEO_LATITUDE=46.58
METEO_LONGITUDE=0.34
METEO_TIMEZONE='Europe/Paris'
METEO_CACHE=1

