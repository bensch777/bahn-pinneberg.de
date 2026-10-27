<?php

/** Ein Zuglog-Eintrag wie zl_merge ihn schreibt (nur die Felder, die der Fahrplan liest). */
function t_fp_eintrag($station, $dir, $zug, $plan, $linie = 'RB 61', $ziel = 'Hamburg Hbf', $ab = '')
{
    return [$station . '|' . $dir . '|' . $zug => ['zug' => $zug, 'linie' => $linie, 'ziel' => $ziel, 'ab' => $ab, 'station' => $station, 'dir' => $dir, 'plan' => $plan]];
}

function test_fahrplan_aus_zuglog()
{
    $logs = [];
    // Mo 12. bis Fr 16. Oktober 2026, dazu Samstag
    foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $tag) {
        $logs[$tag] = t_fp_eintrag('APD', 'hin', '75515', '08:13')
            + t_fp_eintrag('APD', 'hin', '81871', '07:18', 'RB 71')
            + t_fp_eintrag('AEL', 'hin', '11111', '07:50') // anderer Bahnhof
            + t_fp_eintrag('AH', 'rueck', '75514', '17:04', 'RB 61', 'Itzehoe', 'Hbf')
            + t_fp_eintrag('ADF', 'rueck', '75514', '17:07', 'RB 61', 'Itzehoe', 'Dammtor') // Dammtor-Zeile desselben Zugs
            + t_fp_eintrag('AH', 'rueck', '81870', '17:20', 'RB 71', 'Wrist', 'Hbf');
    }
    $logs['2026-10-14'] += t_fp_eintrag('AA', 'rueck', '75514', '17:13', 'RB 61', 'Itzehoe', 'Altona'); // einmal Ersatz ab Altona
    $logs['2026-10-17'] = t_fp_eintrag('APD', 'hin', '75915', '09:13'); // nur samstags
    // Fahrplanwechsel: der 08:13 heisst ab Freitag anders
    $logs['2026-10-16'] = t_fp_eintrag('APD', 'hin', '99999', '08:13') + t_fp_eintrag('APD', 'hin', '81871', '07:18', 'RB 71')
        + t_fp_eintrag('AH', 'rueck', '75514', '17:04', 'RB 61', 'Itzehoe', 'Hbf') + t_fp_eintrag('AH', 'rueck', '81870', '17:20', 'RB 71', 'Wrist', 'Hbf');

    $fp = fp_aus_logs($logs, 'APD');
    t_eq(['07:18 81871', '08:13 99999', '09:13 75915'], array_map(function ($z) { return $z['zeit'] . ' ' . $z['zug']; }, $fp['hin']), 'hin: sortiert, nur Prisdorf, neue Nummer gilt');
    t_eq([1, 2, 3, 4, 5], $fp['hin'][0]['tage'], 'Wochentage Mo-Fr');
    t_eq([6], $fp['hin'][2]['tage'], 'Samstagszug');
    t_eq(['17:04 Hbf 75514', '17:20 Hbf 81870'], array_map(function ($z) { return $z['zeit'] . ' ' . $z['ab'] . ' ' . $z['zug']; }, $fp['rueck']), 'rueck: Hbf statt Dammtor und Altona');

    $fp = fp_aus_logs($logs, 'AIZ');
    t_eq([], $fp['hin'], 'Itzehoe hin unbekannt');
    t_eq(['75514'], array_column($fp['rueck'], 'zug'), 'Itzehoe: der Zug nach Wrist faellt weg');
    t_eq(['81870'], array_column(fp_aus_logs($logs, 'AWST')['rueck'], 'zug'), 'Wrist: nur der RB 71');
}
