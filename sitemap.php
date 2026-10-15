<?php
/** Sitemap: Startseite, FAQ und Changelog. Wird als sitemap.xml ausgeliefert. */
require __DIR__ . '/api/store.php';

define('SITEMAP_BASE', nordbahn_site_url() . '/');

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$lastmod = date('Y-m-d', max((int) @filemtime(__DIR__ . '/index.php'), (int) @filemtime(__DIR__ . '/dashboard.js')));
$urls = [
    ['loc' => SITEMAP_BASE, 'priority' => '1.0'],
    ['loc' => SITEMAP_BASE . 'faq/', 'priority' => '0.5'],
    ['loc' => SITEMAP_BASE . 'statistik/', 'priority' => '0.6'],
    ['loc' => SITEMAP_BASE . 'changelog/', 'priority' => '0.4'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as $u) {
    echo "\t<url><loc>", htmlspecialchars($u['loc'], ENT_QUOTES, 'UTF-8'), "</loc><lastmod>", $lastmod, "</lastmod><priority>", $u['priority'], "</priority></url>\n";
}
echo "</urlset>\n";
