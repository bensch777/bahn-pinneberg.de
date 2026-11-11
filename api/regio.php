<?php
/**
 * DB-Regio-Zuege fuer Pendler aus Elmshorn als JSON: RE6, RE7 und RE70 ab
 * Elmshorn nach Hamburg und ab Hamburg Hbf/Altona zurueck.
 *
 * URL: /api/regio?bhf=AEL
 *
 * Quelle ist die HVV-Schnittstelle Geofox GTI (api/geofox.php, gf_regio_*). Die
 * Zeilen haben das Format von api/abfahrten (richtungen.hin/rueck) mit
 * quelle=hvv, die Startseite mischt sie in die Nordbahn-Tafel. Ohne Zugang in
 * config.php kommt ok=false und richtungen=null. Ein Stand bis GF_STALE_TTL
 * Sekunden Alter wird sofort geliefert und danach im Hintergrund erneuert.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/geofox.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=30');
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$heimat = isset($_GET['bhf']) ? strtoupper(trim((string) $_GET['bhf'])) : 'AEL';
if (!isset(GF_REGIO_HEIMAT[$heimat])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Für diesen Bahnhof gibt es keine DB-Regio-Züge.', 'bahnhoefe' => array_keys(GF_REGIO_HEIMAT)], $flags);
    exit;
}
if (!gf_aktiv()) {
    echo json_encode(gf_regio_leer(), $flags);
    exit;
}
$cached = nordbahn_read_json(gf_regio_cache_file($heimat));
if (nordbahn_sofort_liefern($cached, GF_TTL, GF_STALE_TTL)) {
    nordbahn_antworten(json_encode($cached, $flags));
    gf_regio_cached($heimat);
    exit;
}
echo json_encode(gf_regio_cached($heimat), $flags);
