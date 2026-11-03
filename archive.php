<?php
/**
 * archive.php - "Archives & search" page.
 *
 * Searches earthquakes by date range, minimum magnitude, keyword (place name) and sort order.
 * All results are placed on the map; the table lists the first TABLE_CAP results.
 *
 * API usage on this page: searchEarthquakes() in core.php calls fetchEarthquakes(), which
 * queries the USGS GeoJSON API with starttime/endtime/minmagnitude. Keyword and sorting are
 * applied afterwards in PHP. Offline, the same filters run on the local table seismes_demo.
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lang.php';

const TABLE_CAP = 200;   // rows listed in the table (all results stay on the map)

$pdo    = getPDO();
$userId = currentUserId($pdo);
$favIds = getUserFavoriteIds($pdo, $userId);

$filters = cleanFilters($_GET);
if (!isset($_GET['minmag'])) {
    $filters['minmag'] = 2.5;     // default: ignore micro-earthquakes
}
$filters['limit'] = 1000;
$hasDates = $filters['start'] !== null || $filters['end'] !== null;
$shown    = $filters;             // values shown in the form

// Default range: the last 30 days. Swap the dates if the user entered them backwards.
if (!$filters['start']) {
    $filters['start'] = gmdate('Y-m-d', time() - 30 * 86400);
}
if (!$filters['end']) {
    $filters['end'] = gmdate('Y-m-d');
}
if ($filters['start'] > $filters['end']) {
    [$filters['start'], $filters['end']] = [$filters['end'], $filters['start']];
}

$feed = searchEarthquakes($filters, $pdo);
if ($feed['status'] === 'offline' && !$hasDates) {
    // Offline without dates: search the whole reference table (1906-2023).
    $filters['start'] = $filters['end'] = null;
    $feed = searchEarthquakes($filters, $pdo);
}
$status      = $feed['status'];
$earthquakes = $feed['data'];
if ($status === 'online') {
    $shown['start'] = $filters['start'];
    $shown['end']   = $filters['end'];
}

pageHeader('archive', 'nav_archive', $status);
?>
    <main class="container">
        <form method="GET" action="archive.php" class="filter-form">
            <div class="form-group">
                <label for="start"><?= e(t('date_start')) ?></label>
                <input type="date" id="start" name="start" class="form-input" value="<?= e($shown['start']) ?>">
            </div>
            <div class="form-group">
                <label for="end"><?= e(t('date_end')) ?></label>
                <input type="date" id="end" name="end" class="form-input" value="<?= e($shown['end']) ?>">
            </div>
            <div class="form-group">
                <label for="a_minmag"><?= e(t('mag_min')) ?></label>
                <select name="minmag" id="a_minmag" class="form-select">
                    <?php foreach ([[0, t('mag_all')], [2.5, 'M 2.5+'], [4.0, 'M 4.0+'], [5.0, 'M 5.0+'], [6.0, 'M 6.0+'], [7.0, 'M 7.0+']] as [$value, $label]): ?>
                        <option value="<?= e((string)$value) ?>"<?= abs($filters['minmag'] - $value) < 0.1 ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group grow">
                <label for="q"><?= e(t('filter_q')) ?></label>
                <input type="search" id="q" name="q" class="form-input" maxlength="60" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('filter_q_ph')) ?>">
            </div>
            <div class="form-group">
                <label for="sort"><?= e(t('sort_label')) ?></label>
                <select name="sort" id="sort" class="form-select">
                    <option value="date"<?= $filters['sort'] === 'date' ? ' selected' : '' ?>><?= e(t('sort_date')) ?></option>
                    <option value="mag"<?= $filters['sort'] === 'mag' ? ' selected' : '' ?>><?= e(t('sort_mag')) ?></option>
                </select>
            </div>
            <button type="submit" class="btn-primary"><?= e(t('btn_search')) ?></button>
            <a href="archive.php" class="btn-secondary"><?= e(t('btn_reset')) ?></a>
        </form>

        <p class="muted small"><strong><?= count($earthquakes) ?></strong> <?= e(t('results_count')) ?><?= count($earthquakes) > TABLE_CAP ? ' - ' . e(t('table_cap')) : '' ?></p>

        <div class="map-wrap">
            <div id="worldMap" class="map-container" role="region" aria-label="<?= e(t('nav_archive')) ?>"></div>
        </div>
        <noscript><p class="alert alert-error"><?= e(t('noscript')) ?></p></noscript>

        <section class="quake-detail" id="quakeDetail" aria-live="polite">
            <p class="muted"><?= e(t('js_hint')) ?></p>
        </section>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= e(t('mag_col')) ?></th>
                        <th><?= e(t('place_col')) ?></th>
                        <th><?= e(t('date_col')) ?></th>
                        <th><?= e(t('coord_col')) ?></th>
                        <th><?= e(t('action_col')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($earthquakes)): ?>
                        <tr><td colspan="5" class="muted center"><?= e(t('no_results_msg')) ?></td></tr>
                    <?php else: foreach (array_slice($earthquakes, 0, TABLE_CAP) as $quake): $isFav = in_array($quake['id'], $favIds, true); ?>
                        <tr class="seisme-row" data-id="<?= e($quake['id']) ?>">
                            <td><span class="badge-mag <?= magClass((float)$quake['magnitude']) ?>">M <?= number_format((float)$quake['magnitude'], 1) ?></span></td>
                            <td class="strong"><?= e($quake['lieu']) ?></td>
                            <td><?= e($quake['date']) ?></td>
                            <td class="mono"><?= e(number_format((float)$quake['lat'], 3, '.', '')) ?>, <?= e(number_format((float)$quake['lon'], 3, '.', '')) ?></td>
                            <td class="actions">
                                <button type="button" class="btn-fav btn-show" data-id="<?= e($quake['id']) ?>"><?= e(t('show_on_map')) ?></button>
                                <?php if ($userId) favButton($quake, $isFav); ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<?php pageFooter(['quakes' => $earthquakes, 'favIds' => $favIds], true); ?>
