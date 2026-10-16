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

    $b = zl_bewertung($z);
    t_eq('Letzte 5 Tage: 1× ausgefallen, 1× Teilausfall, Ø +3 min', $b['text'], 'Text');
    t_eq(null, zl_bewertung($a['AH']['rueck']['75514']), 'zu wenige Tage: keine Aussage');
    t_eq('Letzte 7 Tage: nie ausgefallen, meist pünktlich', zl_bewertung(['tage' => 7, 'ausfaelle' => 0, 'teil' => 0, 'spaet' => 0, 'schnitt' => 0.4, 'max' => 2, 'ausfallquote' => 0])['text'], 'guter Zug');
    t_eq('Letzte 9 Tage: nie ausgefallen, Ø +2,5 min', zl_bewertung(['tage' => 9, 'ausfaelle' => 0, 'teil' => 0, 'spaet' => 2, 'schnitt' => 2.5, 'max' => 9, 'ausfallquote' => 0])['text'], 'Dezimalschnitt');
}

function test_zuglog_dateien_und_anreicherung()
{
    $now = strtotime('2026-10-15 07:30:00 Europe/Berlin');
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        unlink($f);
    }
    @unlink(NB_ZUGSTAT_FILE);
    $r = ['ok' => true, 'richtungen' => ['hin' => [t_zl_zeile('81871', '2026-10-15T07:42:00+02:00', ['verspaetung' => 5])], 'rueck' => []]];
    zl_log($r, 'APD', $now);
    zl_log($r, 'APD', $now + 60);
    $datei = nordbahn_read_json(NB_ZUGLOG_DIR . '/2026-10-15.json');
    t_eq(2, $datei['APD|hin|81871']['n'], 'Tagesdatei zaehlt beide Beobachtungen');
    // Vier weitere Tage, dann reicht es fuer eine Aussage
    foreach ([11, 12, 13, 14] as $d) {
        nordbahn_write_json(NB_ZUGLOG_DIR . "/2026-10-$d.json", ['APD|hin|81871' => $datei['APD|hin|81871'] + []]);
    }
    $stat = zl_statistik($now, true);
    t_eq(5, $stat['beobachtete_tage'], 'fuenf Tage gelesen');
    $mit = zl_anreichern($r, 'APD', $now);
    t_eq('Letzte 5 Tage: nie ausgefallen, Ø +5 min', $mit['richtungen']['hin'][0]['statistik']['text'], 'Zeile bekommt ihre Bewertung');
    t_eq(true, is_array(nordbahn_read_json(NB_ZUGSTAT_FILE)), 'Auswertung gecacht');
    zl_log(['ok' => false], 'APD', $now);
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        unlink($f);
    }
    @unlink(NB_ZUGSTAT_FILE);
}
