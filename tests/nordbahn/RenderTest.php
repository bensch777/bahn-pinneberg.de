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

/* ---------- Letzte Stoerungen (Verlauf) ---------- */

function t_verlauf_data()
{
    $jetzt = time();
    $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };

    return ['stoerungen' => [['key' => 'AKTIV']], 'stoerungen_verlauf' => [
        ['key' => 'AKTIV', 'titel' => 'Noch aktiv', 'first_seen' => $iso($jetzt - 3600), 'last_seen' => $iso($jetzt)],
        ['key' => 'ALT', 'titel' => 'Uralt', 'first_seen' => $iso($jetzt - 10 * 86400), 'last_seen' => $iso($jetzt - 9 * 86400)],
        ['key' => 'B', 'titel' => 'Signalstörung', 'ki_titel' => 'Verspätungen in Elmshorn', 'ki_text' => 'Ein Signal war kaputt.',
            'first_seen' => $iso($jetzt - 5 * 3600), 'last_seen' => $iso($jetzt - 3 * 3600)],
        ['key' => 'C', 'titel' => 'Bauarbeiten', 'first_seen' => $iso($jetzt - 2 * 3600), 'last_seen' => $iso($jetzt - 40 * 60)],
        ['key' => 'KAPUTT', 'titel' => 'ohne Zeit', 'first_seen' => '', 'last_seen' => ''],
    ]];
}

function test_verlauf_items_aufgehobene_juengste_zuerst()
{
    $items = nb_verlauf_items(t_verlauf_data());
    t_eq(['C', 'B', 'ALT'], array_column($items, 'key'), 'aktive und kaputte Eintraege fehlen, Reihenfolge nach Ende');
    t_eq('Verspätungen in Elmshorn', $items[1]['titel'], 'KI-Titel vor technischem Titel');
    t_eq('Bauarbeiten', $items[0]['titel'], 'ohne KI-Titel der technische');
    t_eq(2 * 3600, $items[1]['ende'] - $items[1]['start'], 'Dauer aus first_seen und last_seen');
}

function test_verlauf_render_mit_alter_und_limit()
{
    $items = nb_verlauf_items(t_verlauf_data());
    $html = nb_render_verlauf($items, 6, 7);
    t_eq(2, substr_count($html, '<li>'), 'zehn Tage alte Stoerung faellt raus');
    t_contains('Verspätungen in Elmshorn', $html, 'Titel');
    t_contains('Ein Signal war kaputt.', $html, 'Pendler-Text');
    t_contains('2 Std.', $html, 'Dauer');
    t_contains('1 Std. 20 Min.', $html, 'Dauer mit Minuten');
    t_eq(1, substr_count(nb_render_verlauf($items, 1, 7), '<li>'), 'Limit');
    t_contains('Keine aufgehobene Störung in den letzten 7 Tagen.', nb_render_verlauf([], 6, 7), 'leer');
    t_contains('Noch keine Störung erfasst.', nb_render_verlauf([], 6, 0), 'leer ohne Altersgrenze');
}

function test_dauer_format()
{
    t_eq('1 Min.', nb_dauer(20), 'mindestens eine Minute');
    t_eq('35 Min.', nb_dauer(35 * 60), 'Minuten');
    t_eq('3 Std.', nb_dauer(3 * 3600), 'volle Stunden');
    t_eq('2 Std. 10 Min.', nb_dauer(2 * 3600 + 10 * 60), 'Stunden und Minuten');
}
