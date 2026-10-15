<?php
/**
 * Empfaengt das Datenpaket fuer das Nordbahn-Dashboard aus n8n.
 *
 * URL: /api/push (POST, JSON)
 *
 * Der n8n-Workflow "Nordbahn Dashboard Push" ruft diesen Endpunkt alle fuenf
 * Minuten auf und schickt im Header X-Nordbahn-Token den Wert, der hier als
 * NORDBAHN_PUSH_TOKEN in config.php hinterlegt ist (siehe
 * config.example.php). Ohne passenden Token wird nichts angenommen.
 *
 * Das Paket wird bereinigt, als cache/data.json abgelegt und die
 * aktiven Stoerungen wandern ins Logbuch cache/stoerungen-log.json.
 * cache/ ist weder im Repo noch im Deploy, die Daten ueberleben also einen
 * Deploy, landen aber nie in Git.
 */

require __DIR__ . '/store.php';

const PUSH_MAX_BYTES = 4 * 1024 * 1024;

/** Antwortet als JSON und beendet das Skript. */
function respond($status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Nur POST.']);
}

$config = nordbahn_config();
$expected = (string) ($config['NORDBAHN_PUSH_TOKEN'] ?? '');
if ($expected === '') {
    respond(503, ['ok' => false, 'error' => 'NORDBAHN_PUSH_TOKEN ist in config.php nicht gesetzt.']);
}

$given = (string) ($_SERVER['HTTP_X_NORDBAHN_TOKEN'] ?? '');
if ($given === '' || !hash_equals($expected, $given)) {
    respond(403, ['ok' => false, 'error' => 'Token fehlt oder passt nicht.']);
}

$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > PUSH_MAX_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Paket zu gross.']);
}

$raw = file_get_contents('php://input', false, null, 0, PUSH_MAX_BYTES + 1);
if ($raw === false || strlen($raw) > PUSH_MAX_BYTES) {
    respond(413, ['ok' => false, 'error' => 'Paket zu gross.']);
}

$payload = nordbahn_clean_payload(json_decode($raw, true));
if ($payload === null) {
    respond(400, ['ok' => false, 'error' => 'Kein gueltiges Paket: eilmeldungen fehlt.']);
}

$now = gmdate('Y-m-d\TH:i:s\Z');
$payload['received_at'] = $now;

if (!nordbahn_write_json(NORDBAHN_DATA_FILE, $payload)) {
    respond(500, ['ok' => false, 'error' => 'cache/ ist nicht beschreibbar.']);
}

$logSize = nordbahn_update_log($payload['stoerungen'], $now);

respond(200, [
    'ok'           => true,
    'received_at'  => $now,
    'stoerungen'   => count($payload['stoerungen']),
    'eilmeldungen' => count($payload['eilmeldungen']),
    'live'         => count($payload['eilmeldungen_live']),
    'log'          => $logSize,
]);
