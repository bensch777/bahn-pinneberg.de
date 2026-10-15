<?php
/** Fahrplankarte (api/render.php), gleiche Regeln wie renderBetrieb im Script. */

function t_abf(array $hin, array $rueck = [])
{
    $row = function (array $d) {
        return $d + ['zug' => '1', 'linie' => 'RB 71', 'ziel' => 'Hamburg Hbf', 'ab' => null, 'ausfall' => false, 'verspaetung' => 0, 'teil' => null, 'ersatz' => null, 'ersatz_fuer' => null];
    };

    return ['richtungen' => ['hin' => array_map($row, $hin), 'rueck' => array_map($row, $rueck)]];
}

function test_betrieb_ohne_daten_unbekannt()
{
    t_eq('unknown', nb_status_betrieb(null)['level'], 'ohne Antwort');
}

function test_betrieb_laeuft()
{
    $s = nb_status_betrieb(t_abf([[], ['verspaetung' => 4]], [['ab' => 'Hbf']]));
    t_eq('ok', $s['level'], 'kleine Verspaetung ist kein Problem');
    t_eq('Fahrplan läuft', $s['title'], 'Titel');
}

function test_betrieb_zaehlt_arten()
{
    $s = nb_status_betrieb(t_abf(
        [['ausfall' => true], ['verspaetung' => 12], ['ziel' => 'Hamburg-Altona']],
        [['ab' => 'Hbf'], ['ab' => 'Altona', 'linie' => 'RB 60'], ['ab' => 'Hbf', 'teil' => ['art' => 'beginnt', 'name' => 'Pinneberg']]]
    ));
    t_eq('alert', $s['level'], 'vier von sechs betroffen: gestoert');
    t_eq('4 von 6 Zügen (67 %): 1 Zugausfall, 1 Zug nur ab/bis Pinneberg, dazwischen S-Bahn, Umleitung über Altona, Verspätungen bis 12 Min', $s['detail'], 'Detailtext');
}

function test_betrieb_ersatz_zaehlt_nicht_doppelt()
{
    $s = nb_status_betrieb(t_abf([], [
        ['ab' => 'Hbf', 'ausfall' => true, 'ersatz' => ['ab' => 'Altona']],
        ['ab' => 'Altona', 'ersatz_fuer' => ['ab' => 'Hbf']],
        ['ab' => 'Hbf'], ['ab' => 'Hbf'], ['ab' => 'Hbf'], ['ab' => 'Hbf'],
    ]));
    t_eq('1 von 5 Zügen (20 %): Umleitung über Altona', $s['detail'], 'Ausfall mit Ersatz ist eine Umleitung, die Ersatzzeile zaehlt nicht');
    t_eq('warn', $s['level'], 'ein Fuenftel: beeintraechtigt, nicht gestoert');
}
