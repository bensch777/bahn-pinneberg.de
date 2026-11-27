<?php
/**
 * Fehlerseiten (inc/fehler.php, seiten/fehler.php): Seite im Design fuer Menschen,
 * JSON fuer /api/ und /mcp, und die .htaccess leitet die Fehler dorthin.
 */

function t_fehler_rendern($status, $url)
{
    $root = realpath(__DIR__ . '/../..');
    $code = 'error_reporting(E_ALL); ini_set("display_errors", "1"); $_SERVER["HTTP_HOST"] = "test.local";'
        . '$_SERVER["REQUEST_URI"] = "/seiten/fehler.php"; $_SERVER["REDIRECT_STATUS"] = ' . var_export((string) $status, true) . '; $_SERVER["REDIRECT_URL"] = ' . var_export($url, true) . ';'
        . 'chdir(' . var_export($root . '/seiten', true) . '); include ' . var_export($root . '/seiten/fehler.php', true) . ';';
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1', $zeilen, $exit);
    $out = implode("\n", $zeilen);
    t_assert($exit === 0, 'fehler.php: Exit-Code ' . $exit . "\n" . substr($out, 0, 600));
    t_assert(!preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $out, $m), 'fehler.php: ' . ($m[0] ?? '') . "\n" . substr($out, 0, 600));

    return $out;
}

function test_fehlerseite_fuer_menschen()
{
    $s = t_fehler_rendern(404, '/gibts/nicht');
    t_contains('<h1 class="artikel__titel">Diese Seite gibt es nicht</h1>', $s, '404: Ueberschrift');
    t_contains('Fehler 404', $s, '404: Code in der Dachzeile');
    t_contains('<a class="fehler__knopf" href="/">Zur Startseite</a>', $s, '404: Weg zur Startseite, absolut');
    t_contains('class="site-head"', $s, '404: Kopfleiste');
    t_contains('class="site-foot"', $s, '404: Footer');
    t_contains("localStorage.getItem('nordbahn.theme')", $s, '404: Hell/Dunkel wie ueberall');
    t_contains('<meta name="robots" content="noindex">', $s, '404: nicht indexieren');
    t_assert(strpos($s, 'Neu laden') === false, '404: kein Neu laden');

    $s = t_fehler_rendern(503, '/statistik/');
    t_contains('Gerade nicht erreichbar', $s, '503: Ueberschrift');
    t_contains('href="/statistik/">Neu laden</a>', $s, '503: Neu laden auf derselben Adresse');
    t_contains('Diese Seite gibt es nicht', t_fehler_rendern(999, '/x'), 'unbekannter Code gilt als 404');
    t_contains('href="/&quot;&gt;x">Neu laden', t_fehler_rendern(500, '/">x'), 'Adresse wird escaped');
}

function test_fehler_fuer_api_und_mcp_als_json()
{
    $api = json_decode(t_fehler_rendern(404, '/api/gibtsnicht'), true);
    t_eq(['ok' => false, 'status' => 404, 'error' => 'Nicht gefunden'], $api, 'API: JSON statt Seite');
    t_eq(500, json_decode(t_fehler_rendern(500, '/api/data'), true)['status'] ?? null, 'API: Code im JSON');
    $mcp = json_decode(t_fehler_rendern(503, '/mcp'), true);
    t_eq('2.0', $mcp['jsonrpc'] ?? null, 'MCP: JSON-RPC');
    t_contains('HTTP 503', $mcp['error']['message'] ?? '', 'MCP: Code in der Meldung');
    t_eq('', nb_fehler_art('/mcp-hilfe/'), 'Anleitung zum MCP ist eine Seite');
    t_eq('', nb_fehler_art('/pendler/verwalten/'), 'Verwaltung der Pendlermeldungen ist eine Seite');
}

function test_htaccess_leitet_fehler_an_die_fehlerseite()
{
    $ht = file_get_contents(__DIR__ . '/../../.htaccess');
    foreach ([400, 403, 404, 500, 503] as $code) {
        t_contains('ErrorDocument ' . $code . ' /seiten/fehler.php', $ht, 'ErrorDocument ' . $code);
    }
    t_contains("require \$root . '/seiten/fehler.php';", file_get_contents(__DIR__ . '/../../dev/router.php'), 'router zeigt die Fehlerseite');
}
