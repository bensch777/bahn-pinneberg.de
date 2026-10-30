<?php
/**
 * robots.txt mit absoluter Sitemap-Adresse; wird als robots.txt ausgeliefert.
 * Die lesenden JSON-Endpunkte, die llms.txt nennt, sind erlaubt: Assistenten, die
 * im Auftrag eines Nutzers abrufen (Claude-User, Perplexity-User), halten sich an
 * robots.txt und kaemen sonst nicht an die Abfahrten. Vor "Disallow: /api/", weil
 * manche Crawler die erste passende Regel nehmen statt der laengsten.
 */
require __DIR__ . '/api/store.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "# KI-Assistenten: Beschreibung unter /llms.txt, aktuelle Lage als Text unter /lage.md, MCP-Server unter /mcp\nUser-agent: *\nAllow: /\nAllow: /api/data\nAllow: /api/abfahrten\nAllow: /api/s3\nAllow: /api/karte\nDisallow: /api/\nDisallow: /mcp\nDisallow: /impressum/\n\nSitemap: ", nordbahn_site_url(), "/sitemap.xml\n";
