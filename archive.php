<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lang.php';

const TABLE_CAP = 200;   // lignes listées dans le tableau (tous les résultats restent sur la carte)

$pdo    = getPDO();
$userId = currentUserId($pdo);
$favIds = getUserFavoriteIds($pdo, $userId);

$filters = cleanFilters($_GET);
if (!isset($_GET['minmag'])) $filters['minmag'] = 2.5;       // par défaut : on ignore les micro-séismes
$filters['limit'] = 1000;
$userDate = $filters['start'] !== null || $filters['end'] !== null;
$shown    = $filters;   // valeurs affichées dans le formulaire

// Par défaut : 30 derniers jours ; dates inversées : on les échange
if (!$filters['start']) $filters['start'] = gmdate('Y-m-d', time() - 30 * 86400);
if (!$filters['end'])   $filters['end']   = gmdate('Y-m-d');
if ($filters['start'] > $filters['end']) [$filters['start'], $filters['end']] = [$filters['end'], $filters['start']];

$feed = searchEarthquakes($filters, $pdo);
if ($feed['status'] === 'offline' && !$userDate) {
    // Hors ligne sans dates saisies : toute la base de référence (1906-2023)
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
                    <?php foreach ([[0, t('mag_all')], [2.5, 'M 2.5+'], [4.0, 'M 4.0+'], [5.0, 'M 5.0+'], [6.0, 'M 6.0+'], [7.0, 'M 7.0+']] as [$v, $label]): ?>
                        <option value="<?= e((string)$v) ?>"<?= abs($filters['minmag'] - $v) < 0.1 ? ' selected' : '' ?>><?= e($label) ?></option>
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

        <p class="muted small"><strong><?= count($earthquakes) ?></strong> <?= e(t('results_count')) ?><?= count($earthquakes) > TABLE_CAP ? ' — ' . e(t('table_cap')) : '' ?></p>

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
                    <?php else: foreach (array_slice($earthquakes, 0, TABLE_CAP) as $eq): $isFav = in_array($eq['id'], $favIds, true); ?>
                        <tr class="seisme-row" data-id="<?= e($eq['id']) ?>">
                            <td><span class="badge-mag <?= magClass((float)$eq['magnitude']) ?>">M <?= number_format((float)$eq['magnitude'], 1) ?></span></td>
                            <td class="strong"><?= e($eq['lieu']) ?></td>
                            <td><?= e($eq['date']) ?></td>
                            <td class="mono"><?= e(number_format((float)$eq['lat'], 3, '.', '')) ?>, <?= e(number_format((float)$eq['lon'], 3, '.', '')) ?></td>
                            <td class="actions">
                                <button type="button" class="btn-fav btn-show" data-id="<?= e($eq['id']) ?>"><?= e(t('show_on_map')) ?></button>
                                <?php if ($userId) favButton($eq, $isFav); ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<?php pageFooter(['quakes' => $earthquakes, 'favIds' => $favIds], true); ?>
