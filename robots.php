<?php
/** robots.txt mit absoluter Sitemap-Adresse; wird als robots.txt ausgeliefert. */
require __DIR__ . '/api/store.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\nAllow: /\nDisallow: /api/\n\nSitemap: ", nordbahn_site_url(), "/sitemap.xml\n";
