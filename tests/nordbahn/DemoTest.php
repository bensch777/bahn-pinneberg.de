<?php
/**
 * Demo-Modus (dev/demo.php): fuellt in einem eigenen Prozess einen Wegwerf-Cache und
 * prueft, dass alle Szenarien durch die echte Aufbereitung laufen. So faellt auf, wenn
 * sich Formate aendern und die Demo-Daten nicht mehr passen. Wie alle Tests mit fester
 * Uhrzeit (T_NOW ueber NORDBAHN_DEMO_JETZT), damit das Ergebnis nicht von der Tageszeit
 * abhaengt: der Test laeuft auch vor jedem Deploy.
 */

function t_demo_fuellen($szenario, $dir)
{
    $root = realpath(__DIR__ . '/../..');
    $cmd = 'NORDBAHN_DEMO_DIR=' . escapeshellarg($dir) . ' NORDBAHN_DEMO_JETZT=' . escapeshellarg(T_NOW) . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($root . '/dev/demo.php') . ' ' . escapeshellarg($szenario) . ' 2>&1';
    exec($cmd, $zeilen, $status);
    $out = implode("\n", $zeilen);
    t_assert($status === 0 && !preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $out), 'dev/demo.php ' . $szenario . ': Exit-Code ' . $status . "\n" . substr($out, 0, 600));
}

function test_demo_daten_fuellen_den_cache()
{
    $dir = sys_get_temp_dir() . '/nordbahn-demo-test-' . getmypid();
    $lesen = function ($datei) use ($dir) {
        return json_decode((string) @file_get_contents($dir . '/' . $datei), true);
    };
    $jetzt = nb_ts(T_NOW);
    try {
        t_demo_fuellen('stoerung', $dir);
        $data = $lesen('data.json');
        t_eq(1, count($data['stoerungen'] ?? []), 'stoerung: eine laufende Infrastrukturstoerung');
        t_eq(1, count($data['eilmeldungen_live'] ?? []), 'stoerung: eine Eilmeldung auf nordbahn.de');
        $abf = $lesen('abfahrten-' . NORDBAHN_HEIMAT_DEFAULT . '.json');
        t_eq(true, $abf['ok'] ?? null, 'Abfahrten des Vorgabe-Bahnhofs');
        $hin = $abf['richtungen']['hin'];
        $rueck = $abf['richtungen']['rueck'];
        t_assert(count($hin) >= 4 && count($rueck) >= 4, 'beide Richtungen gefuellt');
        t_assert(count(array_filter($hin, function ($d) { return $d['ausfall']; })) === 1, 'ein Ausfall Richtung Hamburg');
        t_assert(count(array_filter($hin, function ($d) { return $d['verspaetung'] >= 10; })) === 1, 'eine deutliche Verspaetung');
        t_assert(count(array_filter($hin, function ($d) { return !empty($d['gleis_plan']); })) === 1, 'ein Gleiswechsel');
        t_assert(count(array_filter($hin, function ($d) { return ($d['teil']['art'] ?? '') === 'endet' && empty($d['teil']['plan']); })) === 1, 'ein Zug nur bis Pinneberg');
        t_assert(count(array_filter($rueck, function ($d) { return !empty($d['ersatz']); })) === 1, 'ein Ausfall am Hbf mit Ersatz ab Altona');
        t_assert(count(array_filter($rueck, function ($d) { return !empty($d['ersatz_fuer']); })) === 1, 'die Ersatzzeile ab Altona');
        t_assert(nb_ankunft($hin[0]) !== null, 'Zeilen tragen den Laufweg fuer die Ankunft');
        t_eq('alert', nb_status_betrieb($abf)['level'], 'Lagekarte der Nordbahn: gestoert');
        $s3 = $lesen('geofox-hbf.json')['s3'] ?? [];
        t_eq(['warn', '7 von 12 Zügen (58 %): 1 Ausfall, Verspätungen bis 8 Min'], [$s3['level'] ?? null, $s3['detail'] ?? null], 'S3 beeintraechtigt, gemessen an den Abfahrten');
        t_eq(['S3: Verspätungen nach Polizeieinsatz in Stellingen'], array_column(array_filter($s3['meldungen'] ?? [], function ($m) { return empty($m['geplant']); }), 'titel'), 'HVV-Meldung zur S3 steht in der Liste, faerbt die Karte aber nicht');
        t_assert(count($lesen('geofox-regio-ael.json')['richtungen']['hin'] ?? []) > 0, 'DB Regio in Elmshorn');
        t_assert(($lesen('karte.json')['anzahl'] ?? 0) > 0, 'Live-Karte hat Zuege');
        t_assert(count(glob($dir . '/zuglog/*.json') ?: []) >= 14, 'Zuglog der letzten 14 Tage');
        foreach (array_keys(NORDBAHN_BAHNHOEFE) as $bhf) {
            t_eq(true, $lesen('abfahrten-' . $bhf . '.json')['ok'] ?? null, 'Abfahrten fuer ' . $bhf);
        }

        t_demo_fuellen('ruhig', $dir);
        $abf = $lesen('abfahrten-' . NORDBAHN_HEIMAT_DEFAULT . '.json');
        t_eq('ok', nb_status_betrieb($abf)['level'], 'ruhig: Nordbahn planmaessig');
        t_eq([], $lesen('data.json')['stoerungen'], 'ruhig: keine Stoerung');
        t_eq(1, count(nb_ausblick($lesen('data.json'), $jetzt)), 'ruhig: ein Sonderfahrplan im Ausblick');
        t_eq('10:00', t_uhr($abf['fetched_at']), 'die Demo rechnet mit der uebergebenen Uhrzeit');

        t_demo_fuellen('bau', $dir);
        $data = $lesen('data.json');
        t_assert(nb_baufahrplan($data, $jetzt) !== null, 'bau: Baufahrplan ab/bis Altona gilt');
        $abf = $lesen('abfahrten-' . NORDBAHN_HEIMAT_DEFAULT . '.json');
        t_eq('Nordbahn fährt nach Baufahrplan', nb_status_betrieb($abf, nb_baufahrplan($data, $jetzt))['title'], 'bau: Lagekarte nennt den Baufahrplan');

        t_demo_fuellen('leicht', $dir);
        t_eq(1, count(nb_aktuelle_meldungen(['stoerungen_verlauf' => array_values($lesen('stoerungen-log.json'))] + $lesen('data.json'), null, $jetzt)), 'leicht: eine gerade aufgehobene Stoerung unter den aktuellen Meldungen');
    } finally {
        foreach (array_merge(glob($dir . '/zuglog/*') ?: [], glob($dir . '/*') ?: []) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($dir . '/zuglog');
        @rmdir($dir);
    }
}
