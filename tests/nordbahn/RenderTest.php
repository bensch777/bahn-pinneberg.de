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

function test_incident_zeitraum_abgelaufen()
{
    $s = ['key' => 'X', 'titel' => 'Störung', 'ki_titel' => 'Test', 'ki_text' => 'Text', 'beginn' => '2026-10-15T05:00:00Z', 'ende' => '2026-10-15T10:31:00Z'];
    t_contains('voraussichtlich bis 15.10., 12:31 Uhr', nb_render_incident($s), 'laufende Stoerung');
    t_contains('gemeldetes Ende 15.10., 12:31 Uhr überschritten, dauert an', nb_render_incident($s + ['abgelaufen' => true]), 'abgelaufene Stoerung');
    t_eq(true, nordbahn_clean_payload(['eilmeldungen' => [], 'stoerungen' => [['key' => 'X', 'abgelaufen' => true]]])['stoerungen'][0]['abgelaufen'], 'Flag ueberlebt die Bereinigung');
    t_eq(false, nordbahn_clean_payload(['eilmeldungen' => [], 'stoerungen' => [['key' => 'X']]])['stoerungen'][0]['abgelaufen'], 'ohne Flag false');
}

function test_eilmeldung_ki_titel_in_der_liste()
{
    $data = [
        'eilmeldungen' => [
            ['id' => 'em-1', 'title' => 'Zugausfall RB60', 'seen_at' => '2026-10-15T08:00:00Z', 'ki_titel' => 'RB60 um 16:38 ab Itzehoe fällt aus'],
            ['id' => 'em-2', 'title' => 'Einschränkungen', 'seen_at' => '2026-10-15T09:00:00Z', 'ki_titel' => ''],
        ],
        'eilmeldungen_live' => [['id' => 'em-1', 'title' => 'Zugausfall RB60', 'stand' => '2026-10-15T08:30:00Z', 'link' => 'https://www.nordbahn.de/x/']],
    ];
    $items = nb_items($data);
    t_eq('RB60 um 16:38 ab Itzehoe fällt aus', $items[0]['title'], 'KI-Titel ersetzt den Nordbahn-Titel');
    t_eq('Zugausfall RB60', $items[0]['original'], 'Original bleibt erhalten');
    t_eq('Einschränkungen', $items[1]['title'], 'ohne KI-Titel der Nordbahn-Titel');
    $html = nb_render_feed($items, 6, 0);
    t_contains('title="Nordbahn: Zugausfall RB60"', $html, 'Original im Tooltip');
    t_contains('>RB60 um 16:38 ab Itzehoe fällt aus</a>', $html, 'KI-Titel verlinkt');
    $clean = nordbahn_clean_payload(['eilmeldungen' => [['id' => 'em-1', 'title' => 'T', 'seen_at' => '2026-10-15T08:00:00Z', 'ki_titel' => 'KI', 'ki_text' => 'Text']]]);
    t_eq('KI', $clean['eilmeldungen'][0]['ki_titel'], 'ki_titel ueberlebt die Bereinigung');
}

/* ---------- Statistik ---------- */

function test_statistik_kennzahlen()
{
    $now = strtotime('2026-10-15 12:00:00 Europe/Berlin');
    $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
    $verlauf = [
        ['key' => 'A', 'titel' => 'Störung an Signalanlagen – Stromausfall', 'first_seen' => $iso($now - 2 * 86400), 'last_seen' => $iso($now - 2 * 86400 + 3 * 3600)],
        ['key' => 'B', 'titel' => 'Störung an Signalanlagen', 'ki_titel' => 'Signal kaputt in Elmshorn', 'first_seen' => $iso($now - 10 * 86400), 'last_seen' => $iso($now - 10 * 86400 + 5 * 3600)],
        ['key' => 'C', 'titel' => 'Bauarbeiten', 'first_seen' => $iso($now - 40 * 86400), 'last_seen' => $iso($now - 40 * 86400 + 3600)],
        ['key' => 'ALT', 'titel' => 'Personen im Gleis', 'first_seen' => $iso($now - 100 * 86400), 'last_seen' => $iso($now - 100 * 86400 + 600)],
        ['key' => 'KAPUTT', 'titel' => 'x', 'first_seen' => '', 'last_seen' => ''],
    ];
    $s = nb_statistik($verlauf, $now);
    t_eq(4, $s['gesamt'], 'kaputter Eintrag zaehlt nicht');
    t_eq($now - 100 * 86400, $s['seit'], 'aelteste Stoerung');
    t_eq(2, $s['tage30']['n'], 'zwei in den letzten 30 Tagen');
    t_eq(8 * 3600, $s['tage30']['dauer'], 'Gesamtdauer');
    t_eq(4 * 3600, $s['tage30']['schnitt'], 'Schnitt');
    t_eq('Signal kaputt in Elmshorn', $s['tage30']['laengste']['titel'], 'laengste mit KI-Titel');
    t_eq(8, count($s['wochen']), 'acht Wochen');
    t_eq(1, end($s['wochen'])['n'], 'laufende Woche zuletzt, mit der Stoerung von vorgestern');
    t_eq(['Störung an Signalanlagen', 'Bauarbeiten'], array_column($s['ursachen'], 'name'), 'Ursachen der letzten 90 Tage, haeufigste zuerst, Unterursache abgeschnitten');
    t_eq(2, $s['ursachen'][0]['n'], 'zwei Signalstoerungen');
    t_eq(0, nb_bar_stufe(0, 5), 'leerer Balken');
    t_eq(100, nb_bar_stufe(5, 5), 'voller Balken');
    t_eq(5, nb_bar_stufe(1, 100), 'mindestens 5 Prozent, wenn ueberhaupt etwas da ist');
    t_eq(40, nb_bar_stufe(2, 5), 'gerundet auf 5er-Stufe');
}
