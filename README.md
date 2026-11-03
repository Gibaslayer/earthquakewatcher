# EarthquakeWatcher — Station de surveillance sismique

Projet de **Gabriel LE GOFF** — ENSIIE FISA FC, Bases du Web 2026-2027.
Technologies : HTML / CSS / PHP 8 / SQL (SQLite via PDO) / JavaScript, carte Leaflet 1.9.4.

## Lancer le site

### Option A — PHP en ligne de commande (le plus simple)

PHP 8 avec les extensions `pdo_sqlite` et `curl` :

```bash
php -S localhost:8000
```

Puis ouvrir http://localhost:8000/

### Option B - Uniform Server (Windows)

1. Copier le dossier `legoffgabrielstandard` dans le dossier `www` d'Uniform Server.
2. Ouvrir le `php.ini` utilisé par Uniform Server (depuis son panneau de contrôle) et vérifier que ces lignes ne sont **pas** précédées de `;` :

   ```ini
   extension=pdo_sqlite
   extension=sqlite3
   extension=curl
   extension=openssl
   ```

3. Pour voir les erreurs au lieu d'une page blanche, mettre `display_errors = On` (à remettre à `Off` pour une mise en production).
4. Redémarrer Apache (ou Uniform Server), puis ouvrir `http://localhost/legoffgabrielstandard/`.

## Base de données

Le fichier SQL de création et de remplissage initial est **`database.sql`** (3 tables : `utilisateurs`, `favoris`, `seismes_demo`). Il est exécuté automatiquement au premier accès pour créer `database.sqlite` : il n'y a rien à faire à la main.

Pour repartir de zéro, supprimer `database.sqlite` : il sera recréé au prochain accès.

## Compte de démonstration

`demo@earthquakewatcher.net` / `Station2026!`

## Utilisation de l'API

Le site utilise **deux** API :

1. **L'API publique USGS** (appelée par le serveur, dans `core.php`, fonction `fetchEarthquakes()`) :
   `https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&starttime=…&minmagnitude=…&limit=…`
   Le serveur attend 3 secondes au maximum, puis bascule sur la table locale. Après un échec, l'API n'est plus appelée pendant 60 secondes.

2. **API interne `api.php`** (appelée par le JavaScript de la page, dans `app.js`) : elle ajoute ou retire un favori. Elle reçoit une requête **POST** en JSON et répond en JSON. Le format complet (champs, codes de réponse) est décrit en tête du fichier `api.php`.

   Exemple d'appel depuis le navigateur :

   ```js
   fetch('api.php', {
       method: 'POST',
       headers: { 'Content-Type': 'application/json' },
       body: JSON.stringify({ action: 'toggle_fav', seisme_id, lieu, magnitude, date, lat, lon })
   });
   ```

## Fichiers

| Fichier | Rôle |
|---|---|
| `core.php` | Moteur commun : session, base SQLite, appel à l'API USGS, recherche, en-tête et pied de page |
| `lang.php` | Traductions FR / EN |
| `index.php` | Surveillance en direct (carte, fiche, plus forts séismes) |
| `archive.php` | Recherche (dates, magnitude, mot-clé, tri), carte et tableau |
| `compte.php` | Connexion, inscription, profil, liste des favoris |
| `api.php` | API JSON des favoris (ajout / retrait) |
| `app.js` | Carte Leaflet, fiche, filtres, favoris en AJAX |
| `style.css` | Mise en page (thème sombre unique) et version mobile |
| `leaflet.js`, `leaflet.css` | Bibliothèque de carte Leaflet 1.9.4 (fournie dans le dossier) |
| `database.sql` | Création des 3 tables, compte démo, 10 séismes de référence |
| `rapport.pdf` | Rapport du projet |

La carte utilise les tuiles OpenStreetMap : sans Internet, la carte reste vide mais les marqueurs et la liste restent utilisables.
