<?php
/**
 * api.php - JSON endpoint used by app.js to add and remove favourites.
 *
 * HOW TO CALL IT
 * --------------
 *   Method:       POST only (any other method returns 405)
 *   URL:          api.php
 *   Content-Type: application/json
 *   Body (JSON):
 *     {
 *       "action":     "toggle_fav",
 *       "seisme_id":  "us7000abcd",        // USGS event id 
 *       "lieu":       "10 km N of Somewhere",
 *       "magnitude":  6.2,                 // between -2 and 10
 *       "date":       "2026-10-24 02:09:00",// UTC, optional (defaults to now)
 *       "lat":        28.0,                // -90 to 90
 *       "lon":        139.9                // -180 to 180
 *     }
 *
 * ANSWERS
 * -------
 *   200 {"status":"added"|"removed","message":"..."}  favourite added or removed
 *   400 {"error":"..."}                                invalid data
 *   401 {"error":"..."}                                nobody is logged in
 *   405 {"error":"Method Not Allowed"}                 not a POST request
 *   503 {"error":"..."}                                database unavailable
 *
 * ---------
 * If the earthquake is already a favourite it is removed (DELETE),
 * otherwise it is inserted (INSERT). The response tells the caller which one happened.
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lang.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON response with an HTTP status code and stops the script.
 *
 * @param int   $code HTTP status code.
 * @param array $body Data to encode as JSON.
 */
function jsonOut(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, ['error' => 'Method Not Allowed']);
}

// Accept JSON (from app.js); fall back to classic form fields.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$pdo    = getPDO();
$userId = currentUserId($pdo);

if (($input['action'] ?? '') !== 'toggle_fav') {
    jsonOut(400, ['error' => t('bad_request')]);
}
if (!$userId) {
    jsonOut(401, ['error' => t('login_required')]);
}
if (!$pdo) {
    jsonOut(503, ['error' => t('db_err')]);
}

// Validate the input before touching the database.
$quakeId = trim((string)($input['seisme_id'] ?? ''));
$mag     = (float)($input['magnitude'] ?? 0);
$lat     = (float)($input['lat'] ?? 0);
$lon     = (float)($input['lon'] ?? 0);
if ($quakeId === '' || strlen($quakeId) > 64 || $mag < -2 || $mag > 10 || abs($lat) > 90 || abs($lon) > 180) {
    jsonOut(400, ['error' => t('bad_request')]);
}

try {
    // SELECT: is it already a favourite ?
    $st = $pdo->prepare('SELECT id FROM favoris WHERE utilisateur_id = :u AND seisme_id_externe = :s');
    $st->execute([':u' => $userId, ':s' => $quakeId]);
    $existing = $st->fetchColumn();

    if ($existing) {
        // DELETE: remove the favourite
        $pdo->prepare('DELETE FROM favoris WHERE id = :id AND utilisateur_id = :u')
            ->execute([':id' => $existing, ':u' => $userId]);
        jsonOut(200, ['status' => 'removed', 'message' => t('fav_removed')]);
    }

    // INSERT: add the favourite. OR IGNORE avoids an error if the same request arrives twice.
    $date = trim((string)($input['date'] ?? ''));
    $pdo->prepare('INSERT OR IGNORE INTO favoris (utilisateur_id, seisme_id_externe, lieu, magnitude, date_seisme, latitude, longitude)
                   VALUES (:u, :s, :lieu, :mag, :date, :lat, :lon)')
        ->execute([
            ':u'    => $userId,
            ':s'    => $quakeId,
            ':lieu' => mb_substr(trim((string)($input['lieu'] ?? '')), 0, 200),
            ':mag'  => $mag,
            ':date' => $date !== '' ? mb_substr($date, 0, 19) : gmdate('Y-m-d H:i:s'),
            ':lat'  => $lat,
            ':lon'  => $lon
        ]);
    jsonOut(200, ['status' => 'added', 'message' => t('fav_added')]);
} catch (PDOException $ex) {
    // Database locked or read-only: answer with JSON instead of a PHP error page.
    jsonOut(503, ['error' => t('db_err')]);
}
