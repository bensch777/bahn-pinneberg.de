<?php
/** Zuverlaessigkeit je Zug: Log-Zusammenfuehrung, Auswertung, Bewertung. */

require_once __DIR__ . '/../../api/zuglog.php';

function t_zl_zeile($zug, $zeit, array $o = [])
{
    return array_merge([
        'zug' => $zug, 'zeit' => $zeit, 'zeit_plan' => null, 'verspaetung' => 0, 'linie' => 'RB61', 'ziel' => 'Hamburg Hbf',
        'gleis' => '1', 'gleis_plan' => null, 'ausfall' => false, 'hinweis' => '', 'ab' => null, 'teil' => null,
    ], $o);
}

function test_zuglog_merge_letzter_stand_zaehlt()
{
    $now = strtotime('2026-10-15 07:30:00 Europe/Berlin');
    $r1 = ['ok' => true, 'richtungen' => ['hin' => [t_zl_zeile('81871', '2026-10-15T07:42:00+02:00', ['verspaetung' => 8])], 'rueck' => [t_zl_zeile('75514', '2026-10-15T07:50:00+02:00', ['ab' => 'Altona', 'ziel' => 'Itzehoe'])]]];
    $logs = zl_merge([], $r1, 'APD', $now);
    t_eq(['2026-10-15'], array_keys($logs), 'ein Tag');
    $e = $logs['2026-10-15']['APD|hin|81871'];
    t_eq([8, 8, 1, '07:42', 'APD'], [$e['versp'], $e['max'], $e['n'], $e['plan'], $e['station']], 'erste Beobachtung');
    t_eq('AA', $logs['2026-10-15']['AA|rueck|75514']['station'], 'Rueckrichtung unter dem Hamburger Startbahnhof');

    // Zweite Beobachtung: Verspaetung sinkt, Max bleibt, Gleiswechsel kommt dazu
    $r2 = ['ok' => true, 'richtungen' => ['hin' => [t_zl_zeile('81871', '2026-10-15T07:42:00+02:00', ['verspaetung' => 3, 'gleis' => '2', 'gleis_plan' => '1'])], 'rueck' => []]];
    $logs2 = zl_merge($logs, $r2, 'APD', $now + 60);
    $e = $logs2['2026-10-15']['APD|hin|81871'];
    t_eq([3, 8, 2, true], [$e['versp'], $e['max'], $e['n'], $e['gleis']], 'letzter Stand, hoechste Verspaetung, Zaehler, Gleiswechsel');
    t_eq(true, isset($logs2['2026-10-15']['AA|rueck|75514']), 'unveraenderte Eintraege bleiben im Tag');

    // Beginnt erst unterwegs: Planzeit aus zeit_plan, Teilausfall gemerkt
    $r3 = ['ok' => true, 'richtungen' => ['hin' => [], 'rueck' => [t_zl_zeile('28542', '2026-10-15T08:20:00+02:00', ['zeit_plan' => '2026-10-15T08:03:00+02:00', 'ab' => 'Hbf', 'teil' => ['art' => 'beginnt', 'name' => 'Pinneberg']])]]];
    $e = zl_merge([], $r3, 'APD', $now)['2026-10-15']['AH|rueck|28542'];
    t_eq(['08:03', 'beginnt'], [$e['plan'], $e['teil']], 'Planzeit und Teilausfall');
    $r4 = ['ok' => true, 'richtungen' => ['hin' => [t_zl_zeile('81895', '2026-10-15T23:20:00+02:00', ['ziel' => 'Pinneberg', 'teil' => ['art' => 'endet', 'name' => 'Pinneberg', 'plan' => true]])], 'rueck' => []]];
    t_eq('', zl_merge([], $r4, 'APD', $now)['2026-10-15']['APD|hin|81895']['teil'], 'planmaessiger Kurzlauf ist kein Teilausfall');
    t_eq([], zl_merge([], ['ok' => true, 'richtungen' => ['hin' => [t_zl_zeile('', '2026-10-15T07:42:00+02:00')]]], 'APD', $now), 'ohne Zugnummer nichts');
}

function test_zuglog_auswertung()
{
    $tag = function ($versp, $ausfall = false, $teil = '', $max = null) {
        return ['zug' => '81871', 'linie' => 'RB61', 'ziel' => 'Hamburg Hbf', 'ab' => '', 'station' => 'APD', 'dir' => 'hin', 'plan' => '07:42', 'versp' => $versp, 'max' => $max ?? $versp, 'ausfall' => $ausfall, 'teil' => $teil, 'gleis' => false, 'n' => 3, 't' => 0];
    };
    $logs = [
        '2026-10-10' => ['APD|hin|81871' => $tag(0)],
        '2026-10-11' => ['APD|hin|81871' => $tag(6)],
        '2026-10-12' => ['APD|hin|81871' => $tag(0, true)],
        '2026-10-13' => ['APD|hin|81871' => $tag(2, false, 'endet', 12)],
        '2026-10-14' => ['APD|hin|81871' => $tag(4), 'AH|rueck|75514' => ['zug' => '75514', 'linie' => 'RB71', 'ziel' => 'Wrist', 'ab' => 'Hbf', 'station' => 'AH', 'dir' => 'rueck', 'plan' => '17:05', 'versp' => 0, 'max' => 0, 'ausfall' => false, 'teil' => '', 'gleis' => true, 'n' => 1, 't' => 0]],
    ];
    $a = zl_auswertung($logs);
    $z = $a['APD']['hin']['81871'];
    t_eq([5, 1, 1, 1, 12, 3.0], [$z['tage'], $z['ausfaelle'], $z['teil'], $z['spaet'], $z['max'], $z['schnitt']], 'Tage, Ausfall, Teilausfall, >=5 min, Max, Schnitt ohne Ausfalltag ((0+6+2+4)/4)');
    t_eq([0.2, 0.25], [$z['ausfallquote'], $z['spaetquote']], 'Quoten');
    t_eq('2026-10-14', $z['zuletzt'], 'zuletzt gesehen');
    t_eq(1, $a['AH']['rueck']['75514']['gleis'], 'Gleiswechsel gezaehlt');
}

function test_zuglog_dateien_und_auswertung()
{
    $now = strtotime('2026-10-15 07:30:00 Europe/Berlin');
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        unlink($f);
    }
    array_map('unlink', glob(NORDBAHN_CACHE_DIR . '/zugstat*.json') ?: []);
    $r = ['ok' => true, 'richtungen' => ['hin' => [t_zl_zeile('81871', '2026-10-15T07:42:00+02:00', ['verspaetung' => 5])], 'rueck' => []]];
    zl_log($r, 'APD', $now);
    zl_log($r, 'APD', $now + 60);
    $datei = nordbahn_read_json(NB_ZUGLOG_DIR . '/2026-10-15.json');
    t_eq(2, $datei['APD|hin|81871']['n'], 'Tagesdatei zaehlt beide Beobachtungen');
    // Vier weitere Tage, dann reicht es fuer eine Aussage
    foreach ([11, 12, 13, 14] as $d) {
        nordbahn_write_json(NB_ZUGLOG_DIR . "/2026-10-$d.json", ['APD|hin|81871' => $datei['APD|hin|81871'] + []]);
    }
    $f = zl_filter(['bhf' => 'APD']);
    $d = zl_dashboard($f, $now, true);
    t_eq(5, $d['kennzahlen']['tage_beobachtet'], 'fuenf Tage gelesen');
    t_eq(5, $d['hin']['81871']['tage'], 'Zug in der Auswertung');
    t_eq(1, count(glob(NORDBAHN_CACHE_DIR . '/zugstat-*.json')), 'Auswertung je Filter gecacht');
    zl_log(['ok' => false], 'APD', $now);
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        unlink($f);
    }
    array_map('unlink', glob(NORDBAHN_CACHE_DIR . '/zugstat*.json') ?: []);
}

function test_zuglog_filter()
{
    t_eq(['bhf' => 'APD', 'richtung' => 'beide', 'tage' => 30, 'zeit' => 'alle', 'werktage' => false], zl_filter([]), 'Vorgaben');
    t_eq(['bhf' => 'AEL', 'richtung' => 'hin', 'tage' => 7, 'zeit' => 'morgens', 'werktage' => true], zl_filter(['bhf' => 'ael', 'richtung' => 'hin', 'tage' => '7', 'zeit' => 'morgens', 'werktage' => '1']), 'gueltige Werte');
    t_eq(['bhf' => 'APD', 'richtung' => 'beide', 'tage' => 30, 'zeit' => 'alle', 'werktage' => false], zl_filter(['bhf' => 'XYZ', 'richtung' => 'quer', 'tage' => '999', 'zeit' => 'nachts', 'werktage' => 'ja']), 'Unsinn faellt auf Vorgaben');
}

function test_zuglog_dashboard()
{
    $now = strtotime('2026-10-16 20:00:00 Europe/Berlin'); // Freitag
    $e = function ($zug, $station, $dir, $plan, $versp, $extra = []) {
        return $extra + ['zug' => $zug, 'linie' => 'RB61', 'ziel' => $dir === 'hin' ? 'Hamburg Hbf' : 'Itzehoe', 'ab' => $dir === 'hin' ? '' : 'Hbf', 'station' => $station, 'dir' => $dir, 'plan' => $plan, 'versp' => $versp, 'max' => $versp, 'ausfall' => false, 'teil' => '', 'gleis' => false];
    };
    $logs = [
        '2026-10-11' => ['APD|hin|1' => $e('1', 'APD', 'hin', '07:00', 9)],                                   // Sonntag
        '2026-10-15' => ['APD|hin|1' => $e('1', 'APD', 'hin', '07:00', 0), 'APD|hin|2' => $e('2', 'APD', 'hin', '12:00', 6),
                         'AH|rueck|3' => $e('3', 'AH', 'rueck', '17:00', 0, ['ausfall' => true]), 'AH|rueck|4' => $e('4', 'AH', 'rueck', '17:30', 0, ['ziel' => 'Wrist']),
                         'AEL|hin|5' => $e('5', 'AEL', 'hin', '07:10', 0)],
        '2026-10-16' => ['APD|hin|1' => $e('1', 'APD', 'hin', '07:00', 2, ['teil' => 'endet'])],
    ];
    $d = zl_dashboard_rechnen($logs, zl_filter(['tage' => '7']), $now);
    $k = $d['kennzahlen'];
    t_eq([6, 2, 2, 2, 1], [$k['fahrten'], $k['puenktlich'], $k['spaet'], $k['ausfall'], $k['teil']], 'alle Fahrten ab Prisdorf und zurueck, ohne Elmshorn');
    t_eq(round((9 + 0 + 6 + 0 + 2) / 5, 1), $k['schnitt'], 'Schnitt ohne ganz ausgefallene Fahrt');
    t_eq(3, $k['tage_beobachtet'], 'drei Tage mit Log');
    t_eq(7, count($d['verlauf']), 'sieben Tage Verlauf');
    t_eq(['2026-10-10', '2026-10-16'], [$d['verlauf'][0]['tag'], $d['verlauf'][6]['tag']], 'aelteste zuerst');
    t_eq(['puenktlich' => 0, 'spaet' => 0, 'ausfall' => 1], array_intersect_key($d['verlauf'][6], ['puenktlich' => 1, 'spaet' => 1, 'ausfall' => 1]), 'Teilausfall zaehlt als ausgefallen');
    t_eq([1, 2], array_keys($d['hin']), 'Zugtabelle hin');
    t_eq(['AH|3', 'AH|4'], array_keys($d['rueck']), 'Zugtabelle zurueck');

    $d = zl_dashboard_rechnen($logs, zl_filter(['tage' => '7', 'bhf' => 'AIZ', 'richtung' => 'rueck']), $now);
    t_eq(['AH|3'], array_keys($d['rueck']), 'Itzehoe: Zug nach Wrist faellt raus');
    t_eq([], $d['hin'], 'nur Rueckrichtung');

    $d = zl_dashboard_rechnen($logs, zl_filter(['tage' => '7', 'zeit' => 'morgens', 'werktage' => '1']), $now);
    t_eq(2, $d['kennzahlen']['fahrten'], 'morgens an Werktagen: Do und Fr um 7 Uhr, Sonntag nicht');
}
