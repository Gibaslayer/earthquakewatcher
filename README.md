# EarthquakeWatcher — Station de surveillance sismique

Projet de **Gabriel LE GOFF** ENSIIE FISA , Bases du Web 2026-2027.
Technologies : HTML / CSS / PHP 8 / SQL (SQLite) / JavaScript.

## Base de données

Le fichier SQL de création et de remplissage initial est **`database.sql`** (3 tables : `utilisateurs`, `favoris`, `seismes_demo`).
Il est exécuté automatiquement au premier accès pour créer `database.sqlite`.

## Lancer le site

PHP 8+ avec les extensions `pdo_sqlite` et `curl` suffit (pas de serveur de base de données) :

```bash
php -S localhost:8000
```

Puis ouvrir http://localhost:8000/ .

Compte de démonstration : `demo@earthquakewatcher.net` / `Station2026!`

Sans accès Internet, le site bascule seul sur la table `seismes_demo` (indicateur « MODE HORS LIGNE »).

La carte utilise Leaflet 1.9.4 (`leaflet.js` et `leaflet.css`, fournis dans le dossier) avec les tuiles OpenStreetMap ; hors ligne, des contours simplifiés des continents les remplacent.
