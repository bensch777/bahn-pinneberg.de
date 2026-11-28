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
        'seiten/start.php' => [], 'seiten/news.php' => [], 'seiten/aktuell.php' => [], 'seiten/eilmeldungen.php' => ['seite' => '2'], 'seiten/stoerungen.php' => [],
        'seiten/statistik.php' => [], 'seiten/karte.php' => [], 'seiten/faq.php' => [], 'seiten/einstellungen.php' => [], 'seiten/changelog.php' => [], 'seiten/impressum.php' => [], 'seiten/mcp-hilfe.php' => [], 'seiten/nutzungsbedingungen.php' => [],
        'seiten/stoerung.php' => ['key' => 'GIBTSNICHT'], 'seiten/eilmeldung.php' => ['id' => 'em-0'],
        'seiten/sitemap.php' => [], 'seiten/llms.php' => [], 'seiten/lage.php' => [], 'seiten/robots.php' => [], 'api/data.php' => [],
    ];
    if (!gf_aktiv()) {
        // Nur ohne echten Zugang, sonst wuerde der Rauchtest die HVV-Schnittstelle rufen
        $seiten['api/s3.php'] = [];
    }
    foreach ($seiten as $datei => $get) {
        $out = t_seite_rendern($datei, $get);
        t_assert($out !== '', $datei . ': keine Ausgabe');
    }
    t_contains('<h1>Meldungen</h1>', t_seite_rendern('seiten/news.php'), 'news rendert');
    $start = t_seite_rendern('seiten/start.php');
    t_contains('<a class="tool tool--toggle" id="notify-toggle" href="./einstellungen/"', $start, 'Zahnrad fuehrt zu den Einstellungen');
    t_assert(strpos($start, 'id="theme-toggle"') === false, 'kein Hell/Dunkel-Schalter mehr in der Kopfzeile');
    t_assert(strpos($start, 'meine-kurz') === false, 'keine Karte Meine Zuege auf der Startseite');
    t_assert(strpos($start, 'id="notify-menu"') === false, 'kein Glocken-Dropdown mehr');
    $einst = t_seite_rendern('seiten/einstellungen.php');
    foreach (['data-seite="einstellungen"', 'id="meine-suche"', 'id="notify-on"', 'id="daten-loeschen"', 'name="theme-wahl" value="system"', 'href="../einstellungen/" aria-current="page"'] as $teil) {
        t_contains($teil, $einst, 'Einstellungen: ' . $teil);
    }
    $aktuell = t_seite_rendern('seiten/aktuell.php');
    t_contains('<a class="tabs__tab" href="../aktuell/" aria-current="page">Aktiv</a>', $aktuell, 'aktuell rendert mit aktivem Tab');
    t_contains('Gerade ist nichts los', $aktuell, 'aktuell ohne Daten: ruhige Lage');
    // Lagekarten sind reine Anzeige; zu /aktuell/ fuehrt die Navigation (Meldungen, Reiter "Aktiv")
    t_eq(false, strpos($start, 'href="./aktuell/"'), 'Lagekarten der Startseite sind keine Links');
    t_eq(0, preg_match('/<a\b[^>]*\bclass="card status\b/', $start), 'keine Lagekarte als Link');
    t_contains('<div class="card status status--', $start, 'Lagekarten als div');
    // Zuege zuerst: Lagekarten, dann die Abfahrten, erst darunter Stoerungskarten und Meldungen
    $pos = array_map(function ($teil) use ($start) { return strpos($start, $teil); }, ['id="status"', 'id="abfahrten"', 'id="meldungen"', 'id="incidents"', 'id="meldungen-inhalt"']);
    $sortiert = $pos;
    sort($sortiert);
    t_assert(!in_array(false, $pos, true) && $pos === $sortiert, 'Reihenfolge der Startseite: Lage, Abfahrten, Meldungen mit Stoerungskarten');
    t_contains('<section id="meldungen" class="section" aria-labelledby="meldungen-h" hidden>', $start, 'ohne Stoerung und Meldung bleibt der Bereich weg');
    t_eq(12, substr_count($start, 'dep--skelett'), 'Platzhalterzeilen in beiden Nordbahn-Tafeln');
    t_contains("d.className+=' js'", $start, 'Klasse js schon im Head, vor dem ersten Zeichnen');
    t_contains("if(!d.hasAttribute('data-app'))", $start, 'ohne Start von dashboard.js faellt die Klasse wieder weg');
    t_assert((bool) preg_match('/class="boards boards--(nur-hin|rueck-first boards--nur-rueck)"/', $start), 'Richtung der Tafeln vom Server gesetzt');
    t_contains('<p class="visually-hidden" id="ansage" role="status" aria-live="polite"></p>', $start, 'Statuszeile fuer Ansagen');
    t_assert(!preg_match('/class="board__list"[^>]*aria-live/', $start), 'Tafeln sind keine Live-Regionen');
    t_assert(strpos($start, 'id="ausblick"') === false, 'ohne Sonderfahrplan kein Ausblick');
    $karte = t_seite_rendern('seiten/karte.php');
    t_contains('id="karte-strecke"', $karte, 'Live-Karte traegt das Streckennetz');
    t_contains('assets/vendor/leaflet/leaflet.js', $karte, 'Live-Karte laedt Leaflet vom eigenen Host');
    t_contains('id="karte-liste"', $karte, 'Live-Karte hat die Zugliste');
    t_contains('/mcp</code>', t_seite_rendern('seiten/mcp-hilfe.php'), 'Anleitung nennt die Adresse');
    t_contains('id="mcp"', t_seite_rendern('seiten/impressum.php'), 'Datenschutz zum MCP-Server');
    t_contains('Störung nicht gefunden', t_seite_rendern('seiten/stoerung.php', ['key' => 'GIBTSNICHT']), 'unbekannte Stoerung');
    if (!gf_aktiv()) {
        t_contains('"ok":false', t_seite_rendern('api/s3.php'), 'api/s3 ohne Zugang');
        t_contains('NORDBAHN_PUSH_TOKEN ist in config.php nicht gesetzt', t_seite_rendern('api/s3.php', ['roh' => 'x']), 'Diagnose ohne Token abgelehnt');
        t_eq(false, strpos(t_seite_rendern('seiten/start.php'), 'status-s3') !== false, 'ohne Zugang keine S3-Karte');
    }
}

/**
 * .htaccess und dev/router.php kennen dieselben Seiten, und zu jeder gibt es
 * seiten/<name>.php; jedes Ziel einer Regel in der .htaccess existiert.
 */
function test_htaccess_und_router_passen_zu_den_dateien()
{
    $root = realpath(__DIR__ . '/../..');
    $ht = file_get_contents($root . '/.htaccess');
    t_assert(preg_match('#RewriteRule \^\(([a-z|-]+)\)/\$ seiten/\$1\.php#', $ht, $m) === 1, '.htaccess: Regel fuer die Seiten');
    $seiten = explode('|', $m[1]);
    t_assert(preg_match("#\\\$seiten = '([a-z|-]+)';#", file_get_contents($root . '/dev/router.php'), $r) === 1, 'dev/router.php: Liste der Seiten');
    t_eq($seiten, explode('|', $r[1]), 'gleiche Seiten in .htaccess und dev/router.php');
    foreach ($seiten as $s) {
        t_assert(is_file($root . '/seiten/' . $s . '.php'), 'seiten/' . $s . '.php fehlt');
    }
    preg_match_all('#^RewriteRule \S+ ((?:seiten|api|assets)/[^\s?$]+)[\s?]#m', $ht, $ziele);
    foreach ($ziele[1] as $ziel) {
        t_assert(is_file($root . '/' . $ziel), '.htaccess zeigt auf ' . $ziel . ', die Datei fehlt');
    }
    t_assert(count($ziele[1]) >= 10, '.htaccess: Ziele gefunden');
}
