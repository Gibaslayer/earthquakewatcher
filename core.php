<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

define('DB_PATH', __DIR__ . '/database.sqlite');
// EW_USGS_URL permet de pointer vers un faux serveur pour les tests
define('USGS_URL', getenv('EW_USGS_URL') ?: 'https://earthquake.usgs.gov/fdsnws/event/1/query');

function getPDO(): ?PDO {
    static $pdo = null, $tried = false;
    if (!$tried) {
        $tried = true;
        $exists = file_exists(DB_PATH) && filesize(DB_PATH) > 0;
        try {
            $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 2
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            // Première exécution : création des tables depuis database.sql
            if (!$exists) {
                $sqlInit = file_get_contents(__DIR__ . '/database.sql');
                if ($sqlInit === false) throw new PDOException('database.sql introuvable');
                $pdo->exec($sqlInit);
            }
        } catch (PDOException $e) {
            $pdo = null;
            // Un fichier à moitié initialisé serait pris pour une base valide au prochain appel
            if (!$exists && file_exists(DB_PATH)) @unlink(DB_PATH);
        }
    }
    return $pdo;
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfCheck(?string $t): bool {
    return hash_equals($_SESSION['csrf'] ?? '', (string)$t);
}

function httpGet(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT      => 'EarthquakeWatcher-ENSIIE/1.0'
        ]);
        // EW_CAFILE : chemin d'un paquet de certificats, si un antivirus intercepte le HTTPS
        if (getenv('EW_CAFILE')) curl_setopt($ch, CURLOPT_CAINFO, getenv('EW_CAFILE'));
        $r = curl_exec($ch);
        $ok = ($r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
        curl_close($ch);
        return $ok ? (string)$r : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 3,
        'header'  => "User-Agent: EarthquakeWatcher-ENSIIE/1.0\r\n"
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    return $r === false ? null : $r;
}

function cleanFilters(array $in): array {
    $validateDate = function ($v): ?string {
        if (is_string($v) && ($d = DateTime::createFromFormat('Y-m-d', $v)) && $d->format('Y-m-d') === $v) {
            return $v;
        }
        return null;
    };
    return [
        'minmag' => max(0.0, min(10.0, (float)($in['minmag'] ?? 0))),
        'start'  => $validateDate($in['start'] ?? null),
        'end'    => $validateDate($in['end'] ?? null),
        'q'      => mb_substr(trim((string)($in['q'] ?? '')), 0, 60),
        'sort'   => ($in['sort'] ?? '') === 'mag' ? 'mag' : 'date',
        'limit'  => 200,
    ];
}

/** Recherche : fetchEarthquakes() + mot-clé sur le lieu + tri (le même code sert en ligne et hors ligne). */
function searchEarthquakes(array $f, ?PDO $pdo): array {
    $feed = fetchEarthquakes($f, $pdo);
    $q = (string)($f['q'] ?? '');
    if ($q !== '') {
        $feed['data'] = array_values(array_filter($feed['data'], fn($x) => mb_stripos($x['lieu'], $q) !== false));
    }
    if (($f['sort'] ?? 'date') === 'mag') {
        usort($feed['data'], fn($a, $b) => $b['magnitude'] <=> $a['magnitude'] ?: strcmp($b['date'], $a['date']));
    }
    return $feed;
}

/**
 * Séismes : API USGS en priorité, sinon table locale seismes_demo (SELECT).
 * La panne de l'API est mémorisée 60 s en session pour ne pas ralentir chaque page.
 */
function fetchEarthquakes(array $f, ?PDO $pdo): array {
    $f += ['minmag' => 0.0, 'start' => null, 'end' => null, 'limit' => 200];

    if (($_SESSION['usgs_down_until'] ?? 0) < time()) {
        $q = [
            'format'       => 'geojson',
            'orderby'      => 'time',
            'limit'        => $f['limit'],
            'minmagnitude' => $f['minmag'],
            'starttime'    => $f['start'] ?? gmdate('Y-m-d', time() - 7 * 86400)
        ];
        if ($f['end']) {
            $q['endtime'] = $f['end'] . ' 23:59:59';
        }

        $raw  = httpGet(USGS_URL . '?' . http_build_query($q));
        $json = $raw ? json_decode($raw, true) : null;

        if (is_array($json) && isset($json['features'])) {
            $out = [];
            foreach ($json['features'] as $x) {
                $p = $x['properties'] ?? [];
                if (!isset($p['mag'], $p['time'], $x['geometry']['coordinates'][1])) continue;
                $out[] = [
                    'id'        => (string)$x['id'],
                    'lieu'      => $p['place'] ?? 'N/A',
                    'magnitude' => round((float)$p['mag'], 1),
                    'date'      => gmdate('Y-m-d H:i:s', intdiv((int)$p['time'], 1000)),
                    'lat'       => (float)$x['geometry']['coordinates'][1],
                    'lon'       => (float)$x['geometry']['coordinates'][0]
                ];
            }
            return ['status' => 'online', 'data' => $out];
        }
        $_SESSION['usgs_down_until'] = time() + 60;
    }

    $data = [];
    if ($pdo) {
        try {
            $sql = 'SELECT seisme_id_externe AS id, lieu, magnitude, date_seisme AS date, latitude AS lat, longitude AS lon
                    FROM seismes_demo WHERE magnitude >= :m';
            $p = [':m' => $f['minmag']];
            if ($f['start']) {
                $sql .= ' AND date_seisme >= :s';
                $p[':s'] = $f['start'] . ' 00:00:00';
            }
            if ($f['end']) {
                $sql .= ' AND date_seisme <= :e';
                $p[':e'] = $f['end'] . ' 23:59:59';
            }
            $st = $pdo->prepare($sql . ' ORDER BY date_seisme DESC');
            $st->execute($p);
            $data = $st->fetchAll();
        } catch (PDOException $e) {
            $data = [];
        }
    }
    return ['status' => 'offline', 'data' => $data];
}

/**
 * Utilisateur connecté, vérifié en base : si le compte n'existe plus (base réinitialisée),
 * la session est nettoyée au lieu de provoquer des erreurs de clé étrangère plus loin.
 */
function currentUserId(?PDO $pdo): ?int {
    $id = $_SESSION['user_id'] ?? null;
    if (!$id || !$pdo) return null;
    try {
        $st = $pdo->prepare('SELECT 1 FROM utilisateurs WHERE id = :id');
        $st->execute([':id' => $id]);
        if ($st->fetchColumn()) return (int)$id;
    } catch (PDOException $e) {
        return null;
    }
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
    return null;
}

function getUserFavoriteIds(?PDO $pdo, ?int $userId): array {
    if (!$pdo || !$userId) return [];
    try {
        $st = $pdo->prepare('SELECT seisme_id_externe FROM favoris WHERE utilisateur_id = :u');
        $st->execute([':u' => $userId]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/** Favoris complets (pour afficher sur la carte des séismes qui ne sont plus dans le flux récent). */
function getUserFavorites(?PDO $pdo, ?int $userId): array {
    if (!$pdo || !$userId) return [];
    try {
        $st = $pdo->prepare('SELECT seisme_id_externe AS id, lieu, magnitude, date_seisme AS date, latitude AS lat, longitude AS lon
                             FROM favoris WHERE utilisateur_id = :u ORDER BY date_seisme DESC');
        $st->execute([':u' => $userId]);
        return array_map(fn($r) => ['id' => (string)$r['id'], 'lieu' => $r['lieu'], 'magnitude' => (float)$r['magnitude'],
                                    'date' => $r['date'], 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon']], $st->fetchAll());
    } catch (PDOException $e) {
        return [];
    }
}

function magClass(float $m): string {
    return $m >= 6.0 ? 'mag-high' : ($m >= 4.0 ? 'mag-med' : 'mag-low');
}

/** URL courante avec un autre paramètre ?lang= (conserve les filtres). */
function langSwitchUrl(): string {
    $q = $_GET;
    $q['lang'] = t('switch_lang_code');
    return basename($_SERVER['SCRIPT_NAME']) . '?' . http_build_query($q);
}

/** En-tête commun : titre, menu, boutons langue et jour/nuit, bandeau d'état. */
function pageHeader(string $page, string $titleKey, ?string $status = null): void {
    global $currentLang;
    $nav = ['index' => 'nav_live', 'archive' => 'nav_archive', 'compte' => 'nav_account'];
    ?>
<!DOCTYPE html>
<html lang="<?= e($currentLang) ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('app_name')) ?> - <?= e(t($titleKey)) ?></title>
    <script>try{var th=localStorage.getItem('theme');if(th==='light'||th==='dark')document.documentElement.setAttribute('data-theme',th);}catch(x){}</script>
    <?php if ($page === 'index' || $page === 'archive'): ?><link rel="stylesheet" href="leaflet.css"><?php endif; ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 24'%3E%3Cpath d='M1 12h10l3-9 5 18 5-14 3 5h20' fill='none' stroke='%23c4481c' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="style.css">
</head>
<body data-page="<?= e($page) ?>">
    <header class="station-header">
        <div class="brand-zone">
            <svg class="logo" viewBox="0 0 48 24" aria-hidden="true"><path d="M1 12h10l3-9 5 18 5-14 3 5h20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div>
                <a href="index.php" class="brand-title"><?= e(t('app_name')) ?></a>
                <span class="brand-sub"><?= e(t('station_subtitle')) ?></span>
            </div>
        </div>
        <button class="burger-btn" id="burgerBtn" type="button" aria-label="Menu" aria-expanded="false">☰</button>
        <nav class="station-nav" id="mainNav">
            <ul class="nav-links">
                <?php foreach ($nav as $p => $k): ?>
                    <li><a href="<?= e($p) ?>.php"<?= $p === $page ? ' class="active"' : '' ?>><?= e(t($k)) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="nav-actions">
                <a href="<?= e(langSwitchUrl()) ?>" class="btn-lang" id="langBtn"><?= e(t('switch_lang_btn')) ?></a>
                <button type="button" class="btn-lang" id="themeBtn"
                        data-label-light="<?= e(t('theme_to_light')) ?>"
                        data-label-dark="<?= e(t('theme_to_dark')) ?>"><?= e(t('theme_to_light')) ?></button>
            </div>
        </nav>
    </header>
    <?php if ($status !== null): ?>
    <div class="status-bar <?= $status === 'online' ? 'online' : 'offline' ?>">
        <span>● <?= e(t($status === 'online' ? 'status_online' : 'status_offline')) ?></span>
        <?php if ($status === 'offline'): ?><span><?= e(t('demo_badge')) ?></span><?php endif; ?>
    </div>
    <?php endif;
}

/** Pied de page commun (crédits + contact) et chargement des scripts. */
function pageFooter(array $jsData = [], bool $withMap = false): void {
    $keys = ['js_err_server', 'js_err_update', 'btn_add_fav', 'fav_exists', 'filter_only_favs', 'filter_all',
             'js_hint', 'js_login_to_fav', 'js_usgs', 'js_events', 'js_strongest', 'js_latest', 'js_none',
             'no_favs_msg'];
    foreach ($keys as $k) $jsData['i18n'][$k] = t($k);
    $jsData['logged'] = !empty($_SESSION['user_id']);
    $jsData['csrf']   = csrfToken();
    $jsData['lang']   = $GLOBALS['currentLang'];
    ?>
    <footer class="station-footer">
        <span><?= e(t('credits')) ?></span>
        <span><?= e(t('contact')) ?></span>
    </footer>
    <script>window.EW = <?= json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php if ($withMap): ?><script src="leaflet.js"></script><?php endif; ?>
    <script src="app.js"></script>
</body>
</html>
<?php
}

/** Bouton favori (AJAX) commun à l'accueil et aux archives. */
function favButton(array $eq, bool $isFav): void { ?>
    <button type="button" class="btn-fav btn-fav-action<?= $isFav ? ' active' : '' ?>"
            data-id="<?= e($eq['id']) ?>"
            data-lieu="<?= e($eq['lieu']) ?>"
            data-mag="<?= e((string)$eq['magnitude']) ?>"
            data-date="<?= e($eq['date']) ?>"
            data-lat="<?= e((string)$eq['lat']) ?>"
            data-lon="<?= e((string)$eq['lon']) ?>"
            data-csrf="<?= e(csrfToken()) ?>"><?= e($isFav ? t('fav_exists') : t('btn_add_fav')) ?></button>
<?php }
