<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lang.php';

$pdo    = getPDO();
$userId = currentUserId($pdo);
$favorites = getUserFavorites($pdo, $userId);
$favIds    = array_column($favorites, 'id');

// Un seul chargement (M 2.5+, 7 jours) ; les filtres de magnitude s'appliquent ensuite
// côté navigateur, donc un seuil plus haut affiche toujours moins de séismes.
$feed        = fetchEarthquakes(['minmag' => 2.5, 'limit' => 1000], $pdo);
$status      = $feed['status'];
$earthquakes = $feed['data'];

pageHeader('index', 'nav_live', $status);
?>
    <section class="hero">
        <div class="hero-inner">
            <h1><?= e(t('hero_title')) ?></h1>
            <p><?= e(t($status === 'online' ? 'hero_online' : 'hero_offline')) ?></p>
            <dl class="stats" id="stats" aria-live="polite"></dl>
        </div>
    </section>

    <main class="container">
        <div class="toolbar">
            <div class="form-group">
                <label for="minmag"><?= e(t('mag_min')) ?></label>
                <select id="minmag" class="form-select">
                    <option value="2.5"><?= e(t('mag_all_eq')) ?></option>
                    <option value="4">M 4.0+</option>
                    <option value="5">M 5.0+</option>
                    <option value="6">M 6.0+ (<?= e(t('mag_major')) ?>)</option>
                </select>
            </div>
            <?php if ($userId): ?>
                <button type="button" id="toggleFavFilter" class="btn-secondary" aria-pressed="false"><?= e(t('filter_only_favs')) ?></button>
            <?php endif; ?>
            <ul class="legend">
                <li><span class="dot mag-low"></span><?= e(t('legend_low')) ?></li>
                <li><span class="dot mag-med"></span><?= e(t('legend_med')) ?></li>
                <li><span class="dot mag-high"></span><?= e(t('legend_high')) ?></li>
            </ul>
        </div>

        <noscript><p class="alert alert-error"><?= e(t('noscript')) ?> <a href="archive.php"><?= e(t('nav_archive')) ?></a></p></noscript>

        <div class="map-wrap">
            <div id="worldMap" class="map-container" role="region" aria-label="<?= e(t('nav_live')) ?>"></div>
        </div>

        <section class="quake-detail" id="quakeDetail" aria-live="polite">
            <p class="muted"><?= e(t('js_hint')) ?></p>
        </section>

        <section class="top-quakes">
            <h2><?= e(t('js_on_map')) ?></h2>
            <ul id="topList" class="top-list"></ul>
        </section>
    </main>
<?php pageFooter(['quakes' => $earthquakes, 'favorites' => $favorites, 'favIds' => $favIds], true); ?>
