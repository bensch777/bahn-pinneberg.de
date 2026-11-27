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
    foreach (['09', '12', '13', '14'] as $d) { // Freitag und Mo-Mi, das Wochenende dazwischen zaehlt nicht
        nordbahn_write_json(NB_ZUGLOG_DIR . "/2026-10-$d.json", ['APD|hin|81871' => $datei['APD|hin|81871'] + []]);
    }
    $f = zl_filter(['bhf' => 'APD']);
    $d = zl_dashboard($f, $now, true);
    t_eq(5, $d['kennzahlen']['tage_beobachtet'], 'fuenf Werktage gelesen');
    t_eq(['81871', 5], [$d['top']['unzuverlaessig'][0]['zug'], $d['top']['unzuverlaessig'][0]['fahrten']], 'Zug mit 5 Min. Verspaetung in der Rangliste');
    t_eq(1, count(glob(NORDBAHN_CACHE_DIR . '/zugstat-*.json')), 'Auswertung je Filter gecacht');
    zl_log(['ok' => false], 'APD', $now);
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        unlink($f);
    }
    array_map('unlink', glob(NORDBAHN_CACHE_DIR . '/zugstat*.json') ?: []);
}

function test_zuglog_vorher_und_richtungen()
{
    $jetzt = ['quote' => 0.8, 'fahrten' => 100, 'ausfall' => 5, 'schnitt' => 2.0];
    t_eq(['quote' => 0.7, 'fahrten' => 60, 'ausfall' => 9, 'schnitt' => 3.1], zl_vorher(['quote' => 0.7, 'fahrten' => 60, 'ausfall' => 9, 'schnitt' => 3.1, 'teil' => 1], $jetzt), 'Vorzeitraum mit genug Fahrten');
    t_eq(null, zl_vorher(['quote' => 0.7, 'fahrten' => 40] + $jetzt, $jetzt), 'zu duenn beobachtet: kein Trend');
    t_eq(null, zl_vorher(['quote' => null, 'fahrten' => 0] + $jetzt, $jetzt), 'ohne Fahrten kein Trend');

    $now = strtotime('2026-10-16 20:00:00 Europe/Berlin'); // Freitag
    $e = function ($zug, $station, $dir, $plan, $versp) {
        return ['zug' => $zug, 'linie' => 'RB61', 'ziel' => $dir === 'hin' ? 'Hamburg Hbf' : 'Itzehoe', 'ab' => $dir === 'hin' ? '' : 'Hbf', 'station' => $station, 'dir' => $dir, 'plan' => $plan, 'versp' => $versp, 'max' => $versp, 'ausfall' => false, 'teil' => '', 'gleis' => false];
    };
    $logs = ['2026-10-16' => [
        'APD|hin|1'  => $e('1', 'APD', 'hin', '07:00', 0),
        'APD|hin|2'  => $e('2', 'APD', 'hin', '07:20', 8),
        'AH|rueck|3' => $e('3', 'AH', 'rueck', '17:00', 0),
    ]];
    $k = zl_dashboard_rechnen($logs, zl_filter(['bhf' => 'APD']), $now)['kennzahlen'];
    t_eq(['hin' => ['fahrten' => 2, 'puenktlich' => 1], 'rueck' => ['fahrten' => 1, 'puenktlich' => 1]], $k['richtungen'], 'Kennzahlen je Richtung');
}

function test_zuglog_filter()
{
    t_eq(['bhf' => 'APD', 'betreiber' => 'nordbahn', 'richtung' => 'beide', 'tage' => 30], zl_filter([]), 'Vorgaben');
    t_eq(['bhf' => 'AEL', 'betreiber' => 'nordbahn', 'richtung' => 'hin', 'tage' => 7], zl_filter(['bhf' => 'ael', 'richtung' => 'hin', 'tage' => '7', 'zeit' => 'morgens']), 'gueltige Werte, alte Parameter ignoriert');
    t_eq(['bhf' => 'APD', 'betreiber' => 'nordbahn', 'richtung' => 'beide', 'tage' => 30], zl_filter(['bhf' => 'XYZ', 'richtung' => 'quer', 'tage' => '999']), 'Unsinn faellt auf Vorgaben');
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
    t_eq([4, 2, 0, 2, 1], [$k['fahrten'], $k['puenktlich'], $k['spaet'], $k['ausfall'], $k['teil']], 'nur Pendelfenster an Werktagen: ohne Sonntag, ohne 12 Uhr, ohne Elmshorn');
    t_eq(round((0 + 0 + 2) / 3, 1), $k['schnitt'], 'Schnitt ohne ganz ausgefallene Fahrt');
    t_eq(2, $k['tage_beobachtet'], 'zwei Werktage mit Log');
    t_eq(5, count($d['verlauf']), 'fuenf Werktage Verlauf');
    t_eq(['2026-10-12', '2026-10-16'], [$d['verlauf'][0]['tag'], $d['verlauf'][4]['tag']], 'aelteste zuerst, Wochenende fehlt');
    t_eq(['puenktlich' => 0, 'spaet' => 0, 'ausfall' => 1], array_intersect_key($d['verlauf'][4], ['puenktlich' => 1, 'spaet' => 1, 'ausfall' => 1]), 'Teilausfall zaehlt als ausgefallen');

    $d = zl_dashboard_rechnen($logs, zl_filter(['tage' => '7', 'bhf' => 'AIZ', 'richtung' => 'rueck']), $now);
    t_eq([1, 1], [$d['kennzahlen']['fahrten'], $d['kennzahlen']['ausfall']], 'Itzehoe zurueck: Zug nach Wrist faellt raus');
}

function test_zuglog_ranglisten()
{
    $now = strtotime('2026-10-30 20:00:00 Europe/Berlin'); // Freitag
    $e = function ($zug, $plan, $versp, $ausfall = false) {
        return ['zug' => $zug, 'linie' => 'RB61', 'ziel' => 'Hamburg Hbf', 'ab' => '', 'station' => 'APD', 'dir' => 'hin', 'plan' => $plan, 'versp' => $versp, 'max' => $versp, 'ausfall' => $ausfall, 'teil' => '', 'gleis' => false];
    };
    $logs = [];
    // Zehn Werktage; Zug 1 jeden zweiten Tag verspaetet, Zug 2 einmal ausgefallen, Zug 3 immer puenktlich, Zug 9 nur zweimal (zu selten)
    $tage = ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'];
    foreach ($tage as $i => $tag) {
        $logs[$tag] = [
            'APD|hin|1' => $e('1', '06:50', $i % 2 ? 8 : 0),
            'APD|hin|2' => $e('2', '07:10', 1, $i === 3),
            'APD|hin|3' => $e('3', '07:30', 0),
        ];
        if ($i < 2) {
            $logs[$tag]['APD|hin|9'] = $e('9', '08:10', 20, true);
        }
    }
    $top = zl_dashboard_rechnen($logs, zl_filter(['tage' => '14']), $now)['top'];
    t_eq(5, $top['min'], 'Mindestzahl: halb so oft wie der haeufigste Zug, wenigstens fuenf');
    t_eq(['1', '2'], array_column($top['unzuverlaessig'], 'zug'), 'unzuverlaessig: schlechteste Quote zuerst, seltener Zug fehlt');
    t_eq([0.5, 5, 0], [$top['unzuverlaessig'][0]['quote'], $top['unzuverlaessig'][0]['spaet'], $top['unzuverlaessig'][0]['ausfall']], 'Kennzahlen je Zug');
    t_eq(['3'], array_column($top['zuverlaessig'], 'zug'), 'zuverlaessig ohne Ueberschneidung');
}

function test_zuglog_db_regio_getrennt()
{
    $now = strtotime('2026-11-12 07:00:00 Europe/Berlin');
    $re = function ($zeit, array $o = []) {
        return t_zl_zeile('', $zeit, array_merge(['linie' => 'RE70', 'quelle' => 'hvv'], $o));
    };
    // Elmshorn: RE nach Hamburg und ab Hbf zurueck, ohne Zugnummer
    $r = ['ok' => true, 'richtungen' => [
        'hin'   => [$re('2026-11-12T07:12:00+01:00', ['verspaetung' => 6]), t_zl_zeile('81871', '2026-11-12T07:20:00+01:00')],
        'rueck' => [$re('2026-11-12T07:40:00+01:00', ['ab' => 'Hbf', 'ziel' => 'Kiel Hbf', 'ausfall' => true])],
    ]];
    $logs = zl_merge([], $r, 'AEL', $now);
    $tag = $logs['2026-11-12'];
    t_eq(['RE70 07:12', 'hvv'], [$tag['AEL|hin|RE70 07:12']['zug'], $tag['AEL|hin|RE70 07:12']['quelle']], 'RE unter Linie und Planzeit');
    t_eq(false, isset($tag['AEL|hin|81871']['quelle']), 'Nordbahn ohne quelle');
    t_eq(['AEL'], $tag['AH|rueck|RE70 07:40']['bis'], 'Rueckrichtung merkt den Heimatbahnhof');

    // Pinneberg sieht denselben RE ab Hbf: bis waechst, ohne Duplikat
    $logs2 = zl_merge($logs, ['ok' => true, 'richtungen' => ['hin' => [], 'rueck' => [$re('2026-11-12T07:40:00+01:00', ['ab' => 'Hbf', 'ziel' => 'Kiel Hbf'])]]], 'AP', $now + 60);
    t_eq(['AEL', 'AP'], $logs2['2026-11-12']['AH|rueck|RE70 07:40']['bis'], 'bis vereinigt');

    // Pendelfenster: der RE ab Hbf um 07:40 zaehlt nicht (zurueck erst ab 15 Uhr)
    $nb = zl_dashboard_rechnen($logs, zl_filter(['bhf' => 'AEL', 'tage' => '7']), $now);
    t_eq([1, 0], [$nb['kennzahlen']['fahrten'], $nb['kennzahlen']['ausfall']], 'Nordbahn zaehlt keine RE');
    $db = zl_dashboard_rechnen($logs, zl_filter(['bhf' => 'AEL', 'tage' => '7', 'betreiber' => 'db']), $now);
    t_eq([1, 1, 0], [$db['kennzahlen']['fahrten'], $db['kennzahlen']['spaet'], $db['kennzahlen']['ausfall']], 'DB Regio getrennt');
    $ap = zl_dashboard_rechnen($logs, zl_filter(['bhf' => 'AP', 'tage' => '7', 'betreiber' => 'db']), $now);
    t_eq(0, $ap['kennzahlen']['fahrten'], 'RE nur fuer Bahnhoefe aus bis');
    t_eq('nordbahn', zl_filter(['bhf' => 'APD', 'betreiber' => 'db'])['betreiber'], 'Prisdorf hat keine RE');
}
