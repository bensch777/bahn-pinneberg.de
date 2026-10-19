<?php
/**
 * Aktuelle Lage als Markdown (ausgeliefert als /lage.md): fuer KI-Assistenten,
 * Skripte und alle, die Text statt HTML wollen. Optional ?bhf=KUERZEL fuer den
 * Heimatbahnhof. Der Inhalt entspricht der Startseite.
 */

require __DIR__ . '/api/store.php';
require_once __DIR__ . '/api/render.php';
if (!defined('NORDBAHN_ABF_LIB')) {
    define('NORDBAHN_ABF_LIB', true);
}
require __DIR__ . '/api/abfahrten.php';

$heimat = isset($_GET['bhf']) ? strtoupper(trim((string) $_GET['bhf'])) : NORDBAHN_HEIMAT_DEFAULT;
if (!isset(NORDBAHN_BAHNHOEFE[$heimat])) {
    $heimat = NORDBAHN_HEIMAT_DEFAULT;
}

header('Content-Type: text/markdown; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Robots-Tag: noindex');
header('Access-Control-Allow-Origin: *');

echo nb_lage_md(nordbahn_dataset(), zl_anreichern(abf_cached($heimat), $heimat), $heimat);
