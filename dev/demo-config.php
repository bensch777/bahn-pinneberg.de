<?php
/**
 * Konfiguration fuer den Demo-Modus (dev/demo.php, NORDBAHN_DEMO im Router). Der
 * erfundene HVV-Zugang schaltet S3, HVV-Meldungen und DB Regio ein; abgefragt wird
 * damit nichts, die Antworten kommen aus dem Demo-Cache.
 */

return [
    'NORDBAHN_PUSH_TOKEN'      => '',
    'NORDBAHN_SITE_URL'        => '',
    'NORDBAHN_PLAUSIBLE_JS'    => '',
    'NORDBAHN_GEOFOX_USER'     => 'demo',
    'NORDBAHN_GEOFOX_PASSWORD' => 'demo',
];
