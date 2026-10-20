<?php
/**
 * S-Bahn S3 zwischen Pinneberg und Hamburg sowie HVV-Meldungen als JSON.
 *
 * URL: /api/s3
 *
 * Quelle ist die HVV-Schnittstelle Geofox GTI (api/geofox.php). Ohne Zugang in
 * config.php antwortet der Endpunkt mit ok=false und s3=null. Ein Stand bis
 * GF_STALE_TTL Sekunden Alter wird sofort geliefert und danach im Hintergrund
 * erneuert, wie bei api/abfahrten.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/geofox.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Robots-Tag: noindex');

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (!gf_aktiv()) {
    echo json_encode(gf_leer(), $flags);
    exit;
}
$cached = nordbahn_read_json(GF_CACHE);
if (nordbahn_sofort_liefern($cached, GF_TTL, GF_STALE_TTL)) {
    nordbahn_antworten(json_encode($cached, $flags));
    gf_cached();
    exit;
}
echo json_encode(gf_cached(), $flags);
