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

Pour changer de ville, modifier les valeurs du fichier src/Config/CityCoordinates.php

