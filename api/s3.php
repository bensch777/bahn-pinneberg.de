<?php
/**
 * S-Bahn S3 zwischen Pinneberg und Hamburg sowie HVV-Meldungen als JSON.
 *
 * URL: /api/s3[?stadt=altona|stadthausbruecke|jungfernstieg|hbf]
 *
 * Quelle ist die HVV-Schnittstelle Geofox GTI (api/geofox.php). Ohne Zugang in
 * config.php antwortet der Endpunkt mit ok=false und s3=null. Ein Stand bis
 * GF_STALE_TTL Sekunden Alter wird sofort geliefert und danach im Hintergrund
 * erneuert, wie bei api/abfahrten.
 *
 * Diagnose: /api/s3?roh=<NORDBAHN_PUSH_TOKEN> liefert die Rohantworten der
 * Schnittstelle (init, checkName, departureList, getAnnouncements), um das
 * Antwortformat ohne SSH zu pruefen. Nie gecacht.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/geofox.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if (isset($_GET['roh'])) {
    header('Cache-Control: no-store');
    $token = (string) (nordbahn_config()['NORDBAHN_PUSH_TOKEN'] ?? '');
    if ($token === '') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'NORDBAHN_PUSH_TOKEN ist in config.php nicht gesetzt.'], $flags);
        exit;
    }
    if (!hash_equals($token, (string) $_GET['roh'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token passt nicht.'], $flags);
        exit;
    }
    $zugang = gf_zugang();
    if ($zugang === null) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'NORDBAHN_GEOFOX_USER und NORDBAHN_GEOFOX_PASSWORD sind in config.php nicht gesetzt.'], $flags);
        exit;
    }
    echo json_encode(['ok' => true] + gf_diagnose($zugang), $flags | JSON_PRETTY_PRINT);
    exit;
}

header('Cache-Control: public, max-age=30');
if (!gf_aktiv()) {
    echo json_encode(gf_leer(), $flags);
    exit;
}
$stadt = isset($_GET['stadt']) ? strtolower(trim((string) $_GET['stadt'])) : GF_STADT_DEFAULT;
if (!isset(GF_STADT[$stadt])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannter Stadtbahnhof.', 'stadt' => array_keys(GF_STADT)], $flags);
    exit;
}
$cached = nordbahn_read_json(gf_cache_file($stadt));
if (nordbahn_sofort_liefern($cached, GF_TTL, GF_STALE_TTL)) {
    nordbahn_antworten(json_encode($cached, $flags));
    gf_cached(null, null, null, $stadt);
    exit;
}
echo json_encode(gf_cached(null, null, null, $stadt), $flags);
