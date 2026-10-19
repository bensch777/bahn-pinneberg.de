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
        'index.php' => [], 'news/index.php' => [], 'eilmeldungen/index.php' => ['seite' => '2'], 'stoerungen/index.php' => [],
        'statistik/index.php' => [], 'faq/index.php' => [], 'changelog/index.php' => [], 'impressum/index.php' => [],
        'stoerung.php' => ['key' => 'GIBTSNICHT'], 'eilmeldung.php' => ['id' => 'em-0'],
        'sitemap.php' => [], 'llms.php' => [], 'lage.php' => [], 'robots.php' => [], 'api/data.php' => [],
    ];
    foreach ($seiten as $datei => $get) {
        $out = t_seite_rendern($datei, $get);
        t_assert($out !== '', $datei . ': keine Ausgabe');
    }
    t_contains('<h1>Wichtige Meldungen</h1>', t_seite_rendern('news/index.php'), 'news rendert');
    t_contains('Störung nicht gefunden', t_seite_rendern('stoerung.php', ['key' => 'GIBTSNICHT']), 'unbekannte Stoerung');
}
