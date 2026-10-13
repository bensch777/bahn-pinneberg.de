<?php
/** Sitemap der Subdomain: Startseite und die Bahnhofsseiten. Wird als sitemap.xml ausgeliefert. */
require __DIR__ . '/api/store.php';

const SITEMAP_BASE = 'https://nordbahn.bensch777.de/';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$lastmod = date('Y-m-d', max((int) @filemtime(__DIR__ . '/index.php'), (int) @filemtime(__DIR__ . '/dashboard.js')));
$urls = [['loc' => SITEMAP_BASE, 'priority' => '1.0']];
foreach (NORDBAHN_BAHNHOEFE as $bhf) {
    $urls[] = ['loc' => SITEMAP_BASE . $bhf['slug'] . '/', 'priority' => '0.8'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as $u) {
    echo "\t<url><loc>", htmlspecialchars($u['loc'], ENT_QUOTES, 'UTF-8'), "</loc><lastmod>", $lastmod, "</lastmod><changefreq>always</changefreq><priority>", $u['priority'], "</priority></url>\n";
}
echo "</urlset>\n";
