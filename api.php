<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lang.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, ['error' => 'Method Not Allowed']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

if (!csrfCheck($input['csrf'] ?? '')) {
    jsonOut(403, ['error' => t('csrf_err')]);
}

$pdo    = getPDO();
$userId = currentUserId($pdo);

if (($input['action'] ?? '') !== 'toggle_fav') {
    jsonOut(400, ['error' => t('bad_request')]);
}
if (!$userId) jsonOut(401, ['error' => t('login_required')]);
if (!$pdo)    jsonOut(503, ['error' => t('db_err')]);

$id  = trim((string)($input['seisme_id'] ?? ''));
$mag = (float)($input['magnitude'] ?? 0);
$lat = (float)($input['lat'] ?? 0);
$lon = (float)($input['lon'] ?? 0);
if ($id === '' || strlen($id) > 64 || $mag < -2 || $mag > 10 || abs($lat) > 90 || abs($lon) > 180) {
    jsonOut(400, ['error' => t('bad_request')]);
}

try {
    $st = $pdo->prepare('SELECT id FROM favoris WHERE utilisateur_id = :u AND seisme_id_externe = :s');
    $st->execute([':u' => $userId, ':s' => $id]);
    $existing = $st->fetchColumn();

    if ($existing) {
        // DELETE : retrait d'un favori
        $pdo->prepare('DELETE FROM favoris WHERE id = :id AND utilisateur_id = :u')
            ->execute([':id' => $existing, ':u' => $userId]);
        jsonOut(200, ['status' => 'removed', 'message' => t('fav_removed')]);
    }

    // INSERT : ajout d'un favori (OR IGNORE : un double envoi depuis deux onglets ne provoque pas d'erreur)
    $date = trim((string)($input['date'] ?? ''));
    $pdo->prepare('INSERT OR IGNORE INTO favoris (utilisateur_id, seisme_id_externe, lieu, magnitude, date_seisme, latitude, longitude)
                   VALUES (:u, :s, :lieu, :mag, :date, :lat, :lon)')
        ->execute([
            ':u'    => $userId,
            ':s'    => $id,
            ':lieu' => mb_substr(trim((string)($input['lieu'] ?? '')), 0, 200),
            ':mag'  => $mag,
            ':date' => $date !== '' ? mb_substr($date, 0, 19) : gmdate('Y-m-d H:i:s'),
            ':lat'  => $lat,
            ':lon'  => $lon
        ]);
    jsonOut(200, ['status' => 'added', 'message' => t('fav_added')]);
} catch (PDOException $e) {
    // Base verrouillée ou en lecture seule : réponse JSON propre plutôt qu'une page d'erreur PHP
    jsonOut(503, ['error' => t('db_err')]);
}
