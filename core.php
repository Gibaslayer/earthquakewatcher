<?php
/**
 * core.php - Shared engine of EarthquakeWatcher.
 *
 * Provides: session setup, the SQLite connection (created from database.sql on first use),
 * HTML escaping, the USGS API call with offline fallback,
 * favourite helpers, and the shared page header and footer.
 */
declare(strict_types=1);
date_default_timezone_set('UTC');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Path of the SQLite database file (created automatically on first use).
define('DB_PATH', __DIR__ . '/database.sqlite');
// USGS earthquake API.
define('USGS_URL', 'https://earthquake.usgs.gov/fdsnws/event/1/query');

// Last database error message, displayed in the page header to help debugging.
$GLOBALS['db_error'] = null;

/**
 * Returns the shared PDO connection to the SQLite database, or null if it cannot be opened.
 * If database.sqlite does not exist yet, the tables and demo data are created from database.sql.
 * The connection is opened once per request and cached in a static variable.
 *
 * @return PDO|null
 */
function getPDO(): ?PDO {
    static $pdo = null, $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;
    $exists = file_exists(DB_PATH) && filesize(DB_PATH) > 0;
    try {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 2
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        if (!$exists) {
            $sqlInit = file_get_contents(__DIR__ . '/database.sql');
            if ($sqlInit === false) {
                throw new PDOException('database.sql not found');
            }
            $pdo->exec($sqlInit);
        }
    } catch (PDOException $e) {
        $GLOBALS['db_error'] = $e->getMessage();
        $pdo = null;
        // Do not keep a half-created database: the next request will try again.
        if (!$exists && file_exists(DB_PATH)) {
            @unlink(DB_PATH);
        }
    }
    return $pdo;
}

/**
 * Escapes a string for safe HTML output.
 *
 * @param string|null $s Text to escape (null is treated as an empty string).
 * @return string Escaped text.
 */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Performs a GET request with a 3-second timeout and returns the body, or null on failure.
 * Uses cURL when available.
 *
 * @param string $url Full URL to fetch.
 * @return string|null Response body, or null if the request failed or returned a status other than 200.
 */
function httpGet(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT      => 'EarthquakeWatcher-ENSIIE/1.0'
        ]);
        $body = curl_exec($ch);
        $ok   = ($body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
        return $ok ? (string)$body : null;
    }
    // Fallback without cURL
    $ctx = stream_context_create(['http' => [
        'timeout' => 3,
        'header'  => "User-Agent: EarthquakeWatcher-ENSIIE/1.0\r\n"
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/**
 * Loads the earthquakes of the last 7 days from the USGS API, or from the local table seismes_demo
 * when the API is unreachable. After a failure, the API is not called again for 60 seconds
 * (remembered in the session), so pages stay fast while offline.
 *
 * @param float    $minMag Minimum magnitude.
 * @param int      $limit  Maximum number of earthquakes.
 * @param PDO|null $pdo    Database connection used for the offline fallback.
 * @return array ['status' => 'online'|'offline', 'data' => list of earthquakes].
 *               Each earthquake has: id, lieu, magnitude, date (UTC, Y-m-d H:i:s), lat, lon.
 */
function fetchEarthquakes(float $minMag, int $limit, ?PDO $pdo): array {
    // 1. Try the USGS API unless it failed in the last 60 seconds.
    if (($_SESSION['usgs_down_until'] ?? 0) < time()) {
        $query = [
            'format'       => 'geojson',
            'orderby'      => 'time',
            'limit'        => $limit,
            'minmagnitude' => $minMag,
            'starttime'    => gmdate('Y-m-d', time() - 7 * 86400)
        ];

        $raw  = httpGet(USGS_URL . '?' . http_build_query($query));
        $json = $raw ? json_decode($raw, true) : null;

        if (is_array($json) && isset($json['features'])) {
            $quakes = [];
            foreach ($json['features'] as $feature) {
                $props = $feature['properties'] ?? [];
                if (!isset($props['mag'], $props['time'], $feature['geometry']['coordinates'][1])) {
                    continue;   // skip incomplete records
                }
                $quakes[] = [
                    'id'        => (string)$feature['id'],
                    'lieu'      => $props['place'] ?? 'N/A',
                    'magnitude' => round((float)$props['mag'], 1),
                    'date'      => gmdate('Y-m-d H:i:s', intdiv((int)$props['time'], 1000)),
                    'lat'       => (float)$feature['geometry']['coordinates'][1],
                    'lon'       => (float)$feature['geometry']['coordinates'][0]
                ];
            }
            return ['status' => 'online', 'data' => $quakes];
        }
        $_SESSION['usgs_down_until'] = time() + 60;
    }

    // 2. Offline fallback: the local reference table.
    $data = [];
    if ($pdo) {
        try {
            $st = $pdo->prepare('SELECT seisme_id_externe AS id, lieu, magnitude, date_seisme AS date, latitude AS lat, longitude AS lon
                                 FROM seismes_demo WHERE magnitude >= :m ORDER BY date_seisme DESC LIMIT :l');
            $st->bindValue(':m', $minMag);
            $st->bindValue(':l', $limit, PDO::PARAM_INT);
            $st->execute();
            $data = $st->fetchAll();
        } catch (PDOException $e) {
            $data = [];
        }
    }
    return ['status' => 'offline', 'data' => $data];
}

/**
 * Returns the id of the logged-in user, after checking that the account still exists.
 * If the account was deleted (for example after the database was reset), the session is cleared.
 *
 * @param PDO|null $pdo Database connection.
 * @return int|null User id, or null if nobody is logged in.
 */
function currentUserId(?PDO $pdo): ?int {
    $id = $_SESSION['user_id'] ?? null;
    if (!$id || !$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM utilisateurs WHERE id = :id');
        $st->execute([':id' => $id]);
        if ($st->fetchColumn()) {
            return (int)$id;
        }
    } catch (PDOException $e) {
        return null;
    }
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
    return null;
}

/**
 * Returns the full favourite records of the user (used to draw favourites on the map,
 * even when they are no longer in the recent feed).
 *
 * @param PDO|null $pdo    Database connection.
 * @param int|null $userId User id.
 * @return array List of earthquakes with the same keys as fetchEarthquakes().
 */
function getUserFavorites(?PDO $pdo, ?int $userId): array {
    if (!$pdo || !$userId) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT seisme_id_externe AS id, lieu, magnitude, date_seisme AS date, latitude AS lat, longitude AS lon
                             FROM favoris WHERE utilisateur_id = :u ORDER BY date_seisme DESC');
        $st->execute([':u' => $userId]);
        return array_map(fn($row) => [
            'id'        => (string)$row['id'],
            'lieu'      => $row['lieu'],
            'magnitude' => (float)$row['magnitude'],
            'date'      => $row['date'],
            'lat'       => (float)$row['lat'],
            'lon'       => (float)$row['lon']
        ], $st->fetchAll());
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Returns the CSS class matching a magnitude: green below 4, amber from 4 to 5.9, red from 6.
 *
 * @param float $magnitude Earthquake magnitude.
 * @return string 'mag-low', 'mag-med' or 'mag-high'.
 */
function magClass(float $magnitude): string {
    return $magnitude >= 6.0 ? 'mag-high' : ($magnitude >= 4.0 ? 'mag-med' : 'mag-low');
}

/**
 * Builds the link that switches the language while keeping the current query string (filters).
 *
 * @return string Relative URL such as "index.php?lang=en".
 */
function langSwitchUrl(): string {
    $query = $_GET;
    $query['lang'] = t('switch_lang_code');
    return basename($_SERVER['SCRIPT_NAME']) . '?' . http_build_query($query);
}

/**
 * Prints the document start: <head>, header with navigation and language switch,
 * the status bar (online/offline) and a red banner if the database could not be opened.
 *
 * @param string      $page     Current page ('index' or 'compte'), used for the active link.
 * @param string      $titleKey Translation key of the page title.
 * @param string|null $status   'online', 'offline', or null to hide the status bar.
 */
function pageHeader(string $page, string $titleKey, ?string $status = null): void {
    global $currentLang;
    $navigation = ['index' => 'nav_live', 'compte' => 'nav_account'];
    $dbError = $GLOBALS['db_error'] ?? null;
    ?>
<!DOCTYPE html>
<html lang="<?= e($currentLang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('app_name')) ?> - <?= e(t($titleKey)) ?></title>
    <?php if ($page === 'index'): ?><link rel="stylesheet" href="leaflet.css"><?php endif; ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 24'%3E%3Cpath d='M1 12h10l3-9 5 18 5-14 3 5h20' fill='none' stroke='%23f2994a' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
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
        <button class="burger-btn" id="burgerBtn" type="button" aria-label="Menu" aria-expanded="false">&#9776;</button>
        <nav class="station-nav" id="mainNav">
            <ul class="nav-links">
                <?php foreach ($navigation as $pageName => $key): ?>
                    <li><a href="<?= e($pageName) ?>.php"<?= $pageName === $page ? ' class="active"' : '' ?>><?= e(t($key)) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="nav-actions">
                <a href="<?= e(langSwitchUrl()) ?>" class="btn-lang"><?= e(t('switch_lang_btn')) ?></a>
            </div>
        </nav>
    </header>
    <?php if ($dbError): ?>
    <div class="alert alert-error db-error" role="alert">
        <strong><?= e(t('db_err')) ?></strong> <code><?= e($dbError) ?></code>
    </div>
    <?php endif; ?>
    <?php if ($status !== null): ?>
    <div class="status-bar <?= $status === 'online' ? 'online' : 'offline' ?>">
        <span>&#9679; <?= e(t($status === 'online' ? 'status_online' : 'status_offline')) ?></span>
        <?php if ($status === 'offline'): ?><span><?= e(t('demo_badge')) ?></span><?php endif; ?>
    </div>
    <?php endif;
}

/**
 * Prints the common footer (credits and contact) and the scripts.
 * Translated strings and data needed by app.js are passed to the browser in window.EW.
 *
 * @param array $jsData Extra data for app.js (quakes, favorites, favIds...).
 * @param bool  $withMap True on pages that show the Leaflet map.
 */
function pageFooter(array $jsData = [], bool $withMap = false): void {
    $translationKeys = ['js_err_server', 'js_err_update', 'btn_add_fav', 'fav_exists', 'filter_only_favs', 'filter_all',
                        'js_hint', 'js_login_to_fav', 'js_usgs', 'js_events', 'js_strongest', 'js_latest', 'js_none',
                        'js_on_map', 'no_favs_msg'];
    foreach ($translationKeys as $key) {
        $jsData['i18n'][$key] = t($key);
    }
    $jsData['logged'] = !empty($_SESSION['user_id']);
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
