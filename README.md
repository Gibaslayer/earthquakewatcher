# EarthquakeWatcher - Station de surveillance sismique

Projet de **Gabriel LE GOFF** - ENSIIE FISA, Bases du Web 2026-2027.
Technologies : HTML / CSS / PHP 8 / SQL (SQLite) / JavaScript, carte Leaflet 1.9.4.

## Lancer le site

### PHP en ligne de commande 

PHP 8 avec les extensions `pdo_sqlite` et `curl` :

```bash
php -S localhost:8000
```
### Uniform Server

Dans `php_development.ini` (dossier `core/php83`), les extensions `curl`, `openssl`, `pdo_sqlite` et `sqlite3` doivent être activées. Pour que PHP puisse joindre l'API USGS en HTTPS, indiquer aussi un fichier de certificats, puis redémarrer Apache :

```ini
curl.cainfo = "C:/Uniform_2025_2026/home/us_opt1/vendor/composer/ca-bundle/res/cacert.pem"
openssl.cafile = "C:/Uniform_2025_2026/home/us_opt1/vendor/composer/ca-bundle/res/cacert.pem"
```

Sans cela, le bandeau affiche « MODE HORS LIGNE » et le site utilise la table locale de secours.

## Utilisation d'un LLM

Le sujet autorise l'usage de LLM. Conformément à cette consigne, je précise que j'ai été assisté par un LLM pour ce projet.

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

## Fichiers

| Fichier | Rôle |
|---|---|
| `core.php` | Moteur commun : session, base SQLite, appel à l'API USGS, en-tête et pied de page |
| `lang.php` | Traductions FR / EN |
| `index.php` | Surveillance en direct : carte, fiche du séisme, ajout aux favoris |
| `compte.php` | Connexion, inscription, profil, liste des favoris |
| `api.php` | API JSON des favoris (ajout / retrait) |
| `app.js` | Carte Leaflet, fiche, filtre de magnitude, favoris en AJAX |
| `style.css` | Mise en page (thème sombre unique) et version mobile |
| `leaflet.js`, `leaflet.css` | Bibliothèque de carte Leaflet 1.9.4 (fournie dans le dossier) |
| `database.sql` | Création des 3 tables, compte démo, 10 séismes de référence |
| `rapport.pdf` | Rapport du projet |

La carte utilise les tuiles OpenStreetMap : sans Internet, la carte reste vide mais les marqueurs et la liste restent utilisables.
