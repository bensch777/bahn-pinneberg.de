<?php
/**
 * Rauchtest: jede Seite einmal in einem eigenen PHP-Prozess rendern, ohne
 * Cache-Daten. Faengt doppelte Includes, fehlende Funktionen und Warnungen,
 * die php -l nicht sieht.
 */

function t_seite_rendern($datei, array $get = [])
{
    $root = realpath(__DIR__ . '/../..');
    $code = 'error_reporting(E_ALL); ini_set("display_errors", "1"); $_SERVER["HTTP_HOST"] = "test.local"; $_SERVER["REQUEST_URI"] = "/";'
        . '$_GET = ' . var_export($get, true) . '; chdir(' . var_export(dirname($root . '/' . $datei), true) . '); include ' . var_export($root . '/' . $datei, true) . ';';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1';
    exec($cmd, $zeilen, $status);
    $out = implode("\n", $zeilen);
    t_assert($status === 0, $datei . ': Exit-Code ' . $status . "\n" . substr($out, 0, 600));
    t_assert(!preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $out, $m), $datei . ': ' . ($m[0] ?? '') . "\n" . substr($out, 0, 600));

    return $out;
}

function test_alle_seiten_rendern_ohne_fehler()
{
    $seiten = [
        'index.php' => [], 'news/index.php' => [], 'aktuell/index.php' => [], 'eilmeldungen/index.php' => ['seite' => '2'], 'stoerungen/index.php' => [],
        'statistik/index.php' => [], 'karte/index.php' => [], 'faq/index.php' => [], 'changelog/index.php' => [], 'impressum/index.php' => [],
        'stoerung.php' => ['key' => 'GIBTSNICHT'], 'eilmeldung.php' => ['id' => 'em-0'],
        'sitemap.php' => [], 'llms.php' => [], 'lage.php' => [], 'robots.php' => [], 'api/data.php' => [],
    ];
    if (!gf_aktiv()) {
        // Nur ohne echten Zugang, sonst wuerde der Rauchtest die HVV-Schnittstelle rufen
        $seiten['api/s3.php'] = [];
    }
    foreach ($seiten as $datei => $get) {
        $out = t_seite_rendern($datei, $get);
        t_assert($out !== '', $datei . ': keine Ausgabe');
    }
    t_contains('<h1>Meldungen</h1>', t_seite_rendern('news/index.php'), 'news rendert');
    $aktuell = t_seite_rendern('aktuell/index.php');
    t_contains('<a class="tabs__tab" href="../aktuell/" aria-current="page">Aktiv</a>', $aktuell, 'aktuell rendert mit aktivem Tab');
    t_contains('Gerade ist nichts los', $aktuell, 'aktuell ohne Daten: ruhige Lage');
    t_contains('href="./aktuell/"', t_seite_rendern('index.php'), 'Lagekarten der Startseite verlinken auf /aktuell/');
    $karte = t_seite_rendern('karte/index.php');
    t_contains('id="karte-strecke"', $karte, 'Live-Karte traegt das Streckennetz');
    t_contains('vendor/leaflet/leaflet.js', $karte, 'Live-Karte laedt Leaflet vom eigenen Host');
    t_contains('id="karte-liste"', $karte, 'Live-Karte hat die Zugliste');
    t_contains('Störung nicht gefunden', t_seite_rendern('stoerung.php', ['key' => 'GIBTSNICHT']), 'unbekannte Stoerung');
    if (!gf_aktiv()) {
        t_contains('"ok":false', t_seite_rendern('api/s3.php'), 'api/s3 ohne Zugang');
        t_contains('NORDBAHN_PUSH_TOKEN ist in config.php nicht gesetzt', t_seite_rendern('api/s3.php', ['roh' => 'x']), 'Diagnose ohne Token abgelehnt');
        t_eq(false, strpos(t_seite_rendern('index.php'), 'status-s3') !== false, 'ohne Zugang keine S3-Karte');
    }
}
