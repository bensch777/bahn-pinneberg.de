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
    t_eq('Nordbahn fährt planmäßig', $s['title'], 'Titel');
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

/* ---------- Baufahrplan: RB61/RB71 planmaessig ab/bis Altona ---------- */

/** Sonderfahrplan wie auf nordbahn.de (sev-1025), Zeitraum 26.10. bis 02.11.2026. */
function t_bau(array $override = [])
{
    return $override + [
        'id' => 'sev-1025', 'von' => '2026-10-26', 'bis' => '2026-11-02',
        'title' => '26.10. - 02.11.: Alle Züge der Linien RB 61 und RB 71 fahren ab/bis Hamburg-Altona; Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe',
        'link' => 'https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-1025/',
    ];
}

function test_baufahrplan_nur_im_zeitraum_und_mit_altona()
{
    $data = ['sonderfahrplaene' => [
        t_bau(['id' => 'sev-932', 'title' => 'Gleis 13/14 im Hamburger Hauptbahnhof vom 08.06. bis 30.11.2026 nicht barrierefrei zu erreichen', 'von' => '2026-05-28', 'bis' => '2026-12-31']),
        t_bau(),
    ]];
    $ts = function ($s) { return (new DateTime($s, new DateTimeZone('Europe/Berlin')))->getTimestamp(); };
    t_eq('sev-1025', nb_baufahrplan($data, $ts('2026-10-26 00:05'))['id'] ?? null, 'ab dem ersten Tag, deutsche Zeit');
    t_eq('sev-1025', nb_baufahrplan($data, $ts('2026-11-02 23:55'))['id'] ?? null, 'bis einschliesslich zum letzten Tag');
    t_eq(null, nb_baufahrplan($data, $ts('2026-11-03 00:05')), 'danach nicht mehr');
    t_eq(null, nb_baufahrplan($data, $ts('2026-10-25 23:55')), 'vorher noch nicht');
    t_eq(null, nb_baufahrplan(['sonderfahrplaene' => [t_bau(['title' => 'Ersatzverkehr zwischen Altona und Pinneberg für RB 61'])]], $ts('2026-10-27 08:00')), 'Altona ohne ab/bis ist kein Baufahrplan');
    t_eq(null, nb_baufahrplan([], $ts('2026-10-27 08:00')), 'ohne Sonderfahrplaene');
}

function test_betrieb_baufahrplan_altona_ist_planmaessig_aber_gelb()
{
    $abf = t_abf(
        [['ziel' => 'Hamburg-Altona'], ['ziel' => 'Hamburg-Altona', 'linie' => 'RB 61'], ['ziel' => 'Hamburg-Altona', 'linie' => 'RB 60']],
        [['ab' => 'Altona'], ['ab' => 'Altona', 'linie' => 'RB 61']]
    );
    t_eq('alert', nb_status_betrieb($abf)['level'], 'ohne Sonderfahrplan: Umleitungen');
    $s = nb_status_betrieb($abf, t_bau());
    t_eq('warn', $s['level'], 'mit Sonderfahrplan gelb');
    t_eq('Nordbahn fährt nach Baufahrplan', $s['title'], 'Titel');
    t_eq('Bis 02.11.: Alle Züge der Linien RB 61 und RB 71 fahren ab/bis Hamburg-Altona; Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe', $s['detail'], 'Text des Sonderfahrplans ohne Zeitraum vorn');
}

function test_betrieb_baufahrplan_zaehlt_ausfaelle_und_verspaetungen()
{
    $s = nb_status_betrieb(t_abf(
        [['ziel' => 'Hamburg-Altona', 'verspaetung' => 23], ['ziel' => 'Hamburg-Altona'], ['ziel' => 'Hamburg-Altona'], ['ziel' => 'Hamburg-Altona']],
        [['ab' => 'Altona'], ['ab' => 'Altona'], ['ab' => 'Altona'], ['ab' => 'Altona']]
    ), t_bau());
    t_eq('warn', $s['level'], 'ein Verspaeteter von acht bleibt gelb');
    t_eq('Nordbahn fährt nach Baufahrplan', $s['title'], 'Titel');
    t_contains(' · 1 von 8 Zügen (13 %): Verspätungen bis 23 Min', $s['detail'], 'Verspaetung zaehlt weiter, Altona nicht');

    $s = nb_status_betrieb(t_abf(
        [['ziel' => 'Hamburg-Altona', 'ausfall' => true], ['ziel' => 'Hamburg-Altona', 'ausfall' => true], ['ziel' => 'Hamburg-Altona']],
        [['ab' => 'Altona'], ['ab' => 'Altona']]
    ), t_bau());
    t_eq('alert', $s['level'], 'zwei Ausfaelle von fuenf: gestoert');
    t_eq('Nordbahn gestört', $s['title'], 'Titel bei Stoerung');
    t_contains('Bis 02.11.: ', $s['detail'], 'Sonderfahrplan bleibt im Text');
}

function test_payload_sonderfahrplaene_bereinigt()
{
    $clean = nordbahn_clean_payload(['eilmeldungen' => [], 'sonderfahrplaene' => [
        t_bau(),
        t_bau(['id' => 'em-1']),
        t_bau(['von' => '26.10.2026']),
        t_bau(['link' => 'https://example.org/']),
    ]]);
    t_eq(['sev-1025', 'sev-1025'], array_column($clean['sonderfahrplaene'], 'id'), 'nur sev-IDs mit ISO-Datum');
    t_eq('', $clean['sonderfahrplaene'][1]['link'], 'fremder Link faellt weg');
    t_eq([], nordbahn_clean_payload(['eilmeldungen' => []])['sonderfahrplaene'], 'altes Paket ohne Feld');
}

/* ---------- Ruhiger Seitenaufbau: Platzhalter und Richtung vom Server ---------- */

function test_platzhalter_und_richtung_vom_server()
{
    $html = nb_render_board_skelett();
    t_eq(6, substr_count($html, '<li class="dep dep--skelett" aria-hidden="true">'), 'sechs Platzhalterzeilen, fuer Screenreader versteckt');
    t_contains('<li class="visually-hidden">Lade Abfahrten …</li>', $html, 'Text fuer Screenreader');
    t_assert(strpos($html, 'dep--skelett') < strpos($html, 'visually-hidden'), 'Platzhalter zuerst: die erste Zeile beginnt ohne Trennlinie');
    t_eq(2, substr_count(nb_render_board_skelett(2), 'dep--skelett'), 'Anzahl waehlbar');

    $ts = function ($s) { return (new DateTime($s, new DateTimeZone('Europe/Berlin')))->getTimestamp(); };
    t_eq('hin', nb_richtung_jetzt($ts('2026-10-15 07:10')), 'morgens in die Stadt');
    t_eq('hin', nb_richtung_jetzt($ts('2026-10-15 11:59')), 'bis kurz vor zwoelf');
    t_eq('rueck', nb_richtung_jetzt($ts('2026-10-15 12:00')), 'ab zwoelf zurueck');
    t_eq('hin', nb_richtung_jetzt($ts('2026-10-16 00:30')), 'nach Mitternacht wieder in die Stadt');
    t_eq('boards boards--nur-hin', nb_boards_klasse('hin'), 'Klassen wie orderBoards im Script');
    t_eq('boards boards--rueck-first boards--nur-rueck', nb_boards_klasse('rueck'), 'Rueckweg vorn');
}

/* ---------- Ausblick: geplante Fahrplanaenderungen ---------- */

function test_ausblick_laufende_und_kommende_sonderfahrplaene()
{
    $ts = function ($s) { return (new DateTime($s, new DateTimeZone('Europe/Berlin')))->getTimestamp(); };
    $now = $ts('2026-10-20 08:00'); // Dienstag
    $data = ['sonderfahrplaene' => [
        t_bau(), // 26.10. bis 02.11., Altona
        t_bau(['id' => 'sev-932', 'title' => 'Gleis 13/14 im Hamburger Hauptbahnhof vom 08.06. bis 30.11.2026 nicht barrierefrei zu erreichen', 'von' => '2026-05-28', 'bis' => '2026-12-31']),
        t_bau(['id' => 'sev-1030', 'title' => '19.10. - 21.10.: Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe (RB 61)', 'von' => '2026-10-19', 'bis' => '2026-10-21', 'link' => '']),
        t_bau(['id' => 'sev-1031', 'title' => 'Nachts: letzte Züge der RB71 enden in Pinneberg', 'von' => '2026-10-21', 'bis' => '2026-10-21']),
        t_bau(['id' => 'sev-1032', 'title' => '10.10. - 18.10.: Bauarbeiten RB 61', 'von' => '2026-10-10', 'bis' => '2026-10-18']),
        t_bau(['id' => 'sev-1033', 'title' => '20.11. - 22.11.: Bauarbeiten RB 61', 'von' => '2026-11-20', 'bis' => '2026-11-22']),
        t_bau(['id' => 'sev-1034', 'title' => '22.10. - 23.10.: Ersatzverkehr RB 82 zwischen Bad Oldesloe und Neumünster', 'von' => '2026-10-22', 'bis' => '2026-10-23']),
    ]];
    $liste = nb_ausblick($data, $now);
    t_eq(['sev-1030', 'sev-1031', 'sev-1025'], array_column($liste, 'id'), 'laufende zuerst, dann kommende nach Beginn; ohne Linie, vorbei, zu weit weg und fremde Linie fehlen');
    t_eq([true, false, false], array_column($liste, 'laeuft'), 'laeuft');
    t_eq('Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe (RB 61)', $liste[0]['text'], 'Titel ohne Zeitraum vorn');
    t_eq('Nachts: letzte Züge der RB71 enden in Pinneberg', $liste[1]['text'], 'Titel ohne Datum bleibt, wie er ist');

    t_eq(['Bis morgen', ''], nb_ausblick_wann($liste[0], $now), 'laeuft bis morgen');
    t_eq(['Morgen', ''], nb_ausblick_wann($liste[1], $now), 'ein einzelner Tag');
    t_eq(['Ab Mo., 26.10.', 'bis Mo., 02.11.'], nb_ausblick_wann($liste[2], $now), 'kommt, mehrere Tage');
    t_eq(['Nur noch heute', ''], nb_ausblick_wann($liste[0], $ts('2026-10-21 08:00')), 'letzter Tag');
    t_eq(['Ab morgen', 'bis Mo., 02.11.'], nb_ausblick_wann($liste[2], $ts('2026-10-25 08:00')), 'beginnt morgen');

    // Gilt der Altona-Baufahrplan, nennt ihn die Lagekarte; im Ausblick steht er dann nicht noch einmal
    t_eq([], array_column(nb_ausblick(['sonderfahrplaene' => [t_bau()]], $ts('2026-10-27 08:00')), 'id'), 'aktiver Baufahrplan fehlt im Ausblick');
    t_eq(['sev-1026'], array_column(nb_ausblick(['sonderfahrplaene' => [t_bau(), t_bau(['id' => 'sev-1026', 'title' => 'Ersatzverkehr RB 61 zwischen Elmshorn und Itzehoe'])]], $ts('2026-10-27 08:00')), 'id'), 'andere laufende bleiben');
    t_eq([], nb_ausblick([], $now), 'ohne Sonderfahrplaene');
    t_eq([], nb_ausblick(['sonderfahrplaene' => [t_bau(['von' => '2026-10-28', 'bis' => '2026-10-26'])]], $now), 'Ende vor Beginn zaehlt nicht');

    $html = nb_render_ausblick($liste, $now, true);
    t_contains('<ol class="feed" id="ausblick-feed">', $html, 'Liste');
    t_contains('<li class="is-heute" data-weg-id="sf:sev-1030"><div class="feed__time">Bis morgen</div>', $html, 'laufender Eintrag mit Kennung zum Wegklicken');
    t_contains('Sonderfahrplan <span class="dep__ab dep__ab--aktiv">gilt gerade</span>', $html, 'laufend markiert');
    t_contains('<div class="feed__time">Ab Mo., 26.10.<span>bis Mo., 02.11.</span></div>', $html, 'Zeitraum in zwei Zeilen');
    t_contains('<a href="https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-1025/" rel="noopener nofollow">Alle Züge der Linien RB 61 und RB 71 fahren ab/bis Hamburg-Altona; Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe</a>', $html, 'Link zur Nordbahn');
    t_contains('<p class="feed__title">Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe (RB 61)</p>', $html, 'ohne Link nur Text');
    t_eq(false, strpos(nb_render_ausblick($liste, $now), 'data-weg-id'), 'auf /aktuell/ ohne Knopf');
    t_eq('', nb_render_ausblick([], $now), 'leer');

    $md = nb_lage_md(['received_at' => null, 'stoerungen' => [], 'eilmeldungen' => [], 'eilmeldungen_live' => [], 'stoerungen_verlauf' => []] + $data, null, 'APD', $now);
    t_contains("## Geplante Fahrplanänderungen", $md, 'Abschnitt in lage.md');
    t_contains('- Ab Mo., 26.10. bis Mo., 02.11.: Alle Züge der Linien RB 61 und RB 71 fahren ab/bis Hamburg-Altona; Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe (https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-1025/)', $md, 'Eintrag mit Zeitraum und Link');
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

function test_stoerung_hinweis_ohne_folgen()
{
    t_eq(true, nb_stoerung_hinweis(['ki_folgen' => 'keine', 'wirkungen' => ['Verspätungen'], 'einschraenkung' => 'schwer']), 'KI-Urteil keine gewinnt');
    t_eq(false, nb_stoerung_hinweis(['ki_folgen' => 'verspaetungen', 'wirkungen' => []]), 'KI-Urteil verspaetungen ist Stoerung');
    t_eq(true, nb_stoerung_hinweis(['ki_folgen' => '', 'wirkungen' => [], 'einschraenkung' => 'leicht']), 'ohne Urteil: keine SPNV-Wirkung und leicht ist Hinweis');
    t_eq(false, nb_stoerung_hinweis(['ki_folgen' => '', 'wirkungen' => [], 'einschraenkung' => 'schwer']), 'ohne Urteil: schwer bleibt Stoerung');
    t_eq(false, nb_stoerung_hinweis(['wirkungen' => ['Verspätungen']]), 'mit Wirkung Stoerung');
    t_eq(false, nb_stoerung_hinweis(['key' => 'X', 'titel' => 'ohne Angaben']), 'ohne jede Angabe: im Zweifel Stoerung');
    t_eq('keine', nordbahn_folgen(' Keine '), 'Folgen normalisiert');
    t_eq('', nordbahn_folgen('egal'), 'unbekannter Wert wird leer');

    $jetzt = nb_ts(T_NOW);
    $hinweis = ['key' => 'EID', 'titel' => 'Signalstörung', 'einschraenkung' => 'leicht', 'wirkungen' => ['Verspätungen'], 'ki_titel' => 'Eidelstedt: Betrieb wieder planmäßig', 'ki_text' => 'Keine spürbaren Folgen.', 'ki_folgen' => 'keine', 'beginn' => '2026-10-14T10:00:00Z'];
    $stoerung = ['key' => 'HBF', 'titel' => 'Weichenstörung', 'text' => '', 'einschraenkung' => 'leicht', 'wirkungen' => ['Verspätungen'], 'ki_titel' => '', 'ki_text' => '', 'ki_folgen' => 'verspaetungen', 'beginn' => '2026-10-15T05:00:00Z'];
    $data = ['received_at' => T_NOW, 'quellen' => ['strecken_info_ok' => true], 'stoerungen' => [$hinweis]];
    t_eq(['level' => 'ok', 'title' => 'Keine Infrastrukturstörung', 'detail' => '1 Hinweis ohne Folgen'], nb_status_infra($data), 'nur Hinweise: gruen mit Zeile');
    $data['stoerungen'] = [$hinweis, $stoerung];
    t_eq(['level' => 'warn', 'title' => '1 leichte Infrastrukturstörung', 'detail' => 'Weichenstörung · seit 07:00 Uhr'], nb_status_infra($data, $jetzt), 'Hinweis zaehlt nicht mit, die Karte nennt die Stoerung');
    $data['quellen']['strecken_info_ok'] = false;
    $data['stoerungen'] = [$hinweis];
    t_eq('ok', nb_status_infra($data)['level'], 'Hinweis vorhanden heisst Quelle hat geantwortet');
    t_eq(['stoerungen' => [$stoerung], 'hinweise' => [$hinweis]], nb_stoerungen_teilen([$hinweis, $stoerung]), 'aufgeteilt');

    t_eq(false, nb_wichtig_stoerung($hinweis + ['wichtig' => true], $jetzt - 3 * 86400, $jetzt), 'Hinweis nie wichtig, auch nicht lang oder markiert');
    t_eq(true, nb_wichtig_stoerung($stoerung, $jetzt - 3 * 3600, $jetzt), 'lange Stoerung wichtig');

    $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
    $verlauf = [
        ['key' => 'EID', 'titel' => 'Signalstörung', 'ki_folgen' => 'keine', 'first_seen' => $iso($jetzt - 7200), 'last_seen' => $iso($jetzt - 600)],
        ['key' => 'HBF', 'titel' => 'Weichenstörung', 'ki_folgen' => 'verspaetungen', 'first_seen' => $iso($jetzt - 7200), 'last_seen' => $iso($jetzt - 600)],
        ['key' => 'SGV', 'titel' => 'Nur Güterzüge', 'wirkungen' => [], 'first_seen' => $iso($jetzt - 7200), 'last_seen' => $iso($jetzt - 600)],
    ];
    $d = ['stoerungen' => [], 'stoerungen_verlauf' => $verlauf, 'eilmeldungen' => [], 'eilmeldungen_live' => []];
    t_eq(['HBF'], array_column(nb_verlauf_items($d), 'key'), 'Verlauf ohne Hinweise');
    t_eq(['HBF'], array_column(nb_aktuelle_meldungen($d, null, $jetzt), 'key'), 'aktuelle Meldungen ohne Hinweise');
    $stat = nb_statistik($verlauf, $jetzt, 30);
    t_eq(1, $stat['gesamt'], 'Statistik zaehlt nur die Stoerung');

    $md = nb_lage_md(['received_at' => T_NOW, 'quellen' => ['strecken_info_ok' => true], 'stoerungen' => [$hinweis, $stoerung]] + $d, ['ok' => false], 'APD', $jetzt);
    t_contains('### Hinweise ohne spürbare Folgen', $md, 'lage.md nennt Hinweise getrennt');
    t_contains('- Eidelstedt: Betrieb wieder planmäßig (', $md, 'Hinweis als Zeile');
}

function test_verlauf_items_aufgehobene_juengste_zuerst()
{
    $items = nb_verlauf_items(t_verlauf_data());
    t_eq(['C', 'B', 'ALT'], array_column($items, 'key'), 'aktive und kaputte Eintraege fehlen, Reihenfolge nach Beginn');
    t_eq('Verspätungen in Elmshorn', $items[1]['titel'], 'KI-Titel vor technischem Titel');
    t_eq('Bauarbeiten', $items[0]['titel'], 'ohne KI-Titel der technische');
    t_eq(2 * 3600, $items[1]['ende'] - $items[1]['start'], 'Dauer aus first_seen und last_seen');
}

function test_verlauf_render_mit_alter_und_limit()
{
    $items = nb_verlauf_items(t_verlauf_data());
    $html = nb_render_verlauf($items, 6, 7);
    t_eq(2, substr_count($html, '<li'), 'zehn Tage alte Stoerung faellt raus');
    t_contains('Verspätungen in Elmshorn', $html, 'Titel');
    t_contains('Ein Signal war kaputt.', $html, 'Pendler-Text');
    t_contains('2 Std.', $html, 'Dauer');
    t_contains('1 Std. 20 Min.', $html, 'Dauer mit Minuten');
    t_eq(1, substr_count(nb_render_verlauf($items, 1, 7), '<li'), 'Limit');
    t_contains('Keine aufgehobene Infrastrukturstörung in den letzten 7 Tagen.', nb_render_verlauf([], 6, 7), 'leer');
    t_contains('Keine aufgehobene Infrastrukturstörung in den letzten 24 Stunden.', nb_render_verlauf([], 6, 1), 'leer, ein Tag');
    t_contains('Keine Störung in den letzten 24 Stunden aufgehoben, eine läuft noch (siehe oben).', nb_render_verlauf([], 6, 1, null, 1), 'leer, aber eine aktive');
    t_contains('Keine Störung in den letzten 24 Stunden aufgehoben, 2 laufen noch (siehe oben).', nb_render_verlauf([], 6, 1, null, 2), 'leer, zwei aktive');
    t_eq(2, substr_count(nb_render_verlauf($items, 6, 1), '<li'), 'ein Tag: C (vor 40 Min.) und B (vor 3 Std.) bleiben, ALT nicht');
    t_contains('Noch keine Infrastrukturstörung erfasst.', nb_render_verlauf([], 6, 0), 'leer ohne Altersgrenze');
}

function test_dauer_format()
{
    t_eq('1 Min.', nb_dauer(20), 'mindestens eine Minute');
    t_eq('35 Min.', nb_dauer(35 * 60), 'Minuten');
    t_eq('3 Std.', nb_dauer(3 * 3600), 'volle Stunden');
    t_eq('2 Std. 10 Min.', nb_dauer(2 * 3600 + 10 * 60), 'Stunden und Minuten');
}

function test_zeit_rel_und_zeitraum_satz()
{
    $jetzt = nb_ts(T_NOW); // 15.10.2026, 10:00 Uhr Berlin
    t_eq('heute', nb_fmt_tag_rel($jetzt - 3600, $jetzt), 'heute');
    t_eq('gestern', nb_fmt_tag_rel($jetzt - 86400, $jetzt), 'gestern');
    t_eq('morgen', nb_fmt_tag_rel($jetzt + 86400, $jetzt), 'morgen');
    t_eq('17.10.', nb_fmt_tag_rel($jetzt + 2 * 86400, $jetzt), 'uebermorgen mit Datum');
    t_eq('09:00 Uhr', nb_fmt_zeit_rel($jetzt - 3600, $jetzt), 'heute nur die Uhrzeit');
    t_eq('gestern 10:00 Uhr', nb_fmt_zeit_rel($jetzt - 86400, $jetzt), 'gestern mit Uhrzeit');
    t_eq('Morgen 14:00 Uhr', nb_fmt_zeit_rel($jetzt + 86400 + 4 * 3600, $jetzt, false), 'morgen gross am Satzanfang');
    t_eq('17.10., 10:00 Uhr', nb_fmt_zeit_rel($jetzt + 2 * 86400, $jetzt), 'Datum mit Komma');

    t_eq('Seit 05:50 Uhr (4 Std. 10 Min.) · voraussichtlich bis morgen 14:00 Uhr', nb_zeitraum_satz($jetzt - 250 * 60, $jetzt + 86400 + 4 * 3600, $jetzt), 'laufend mit Ende');
    t_eq('Seit gestern 15:49 Uhr (18 Std. 11 Min.) · länger als gemeldet, Ende war 08:00 Uhr', nb_zeitraum_satz($jetzt - 86400 + 349 * 60, $jetzt - 7200, $jetzt), 'Ende ueberschritten');
    t_eq('Seit 09:30 Uhr (30 Min.)', nb_zeitraum_satz($jetzt - 1800, null, $jetzt), 'ohne Ende');
    t_eq('Gestern 14:50 Uhr bis 18:00 Uhr (3 Std. 10 Min.)', nb_zeitraum_satz($jetzt - 86400 + 290 * 60, null, $jetzt, $jetzt - 86400 + 480 * 60), 'aufgehoben am selben Tag');
    t_eq('Gestern 23:10 Uhr bis 01:00 Uhr (1 Std. 50 Min.)', nb_zeitraum_satz($jetzt - 86400 + 790 * 60, null, $jetzt, $jetzt - 9 * 3600), 'aufgehoben ueber Mitternacht');
    t_eq('1 Tag 3 Std.', nb_dauer(27 * 3600 + 20 * 60), 'Dauer mit Tagen, Minuten fallen weg');
    t_eq('2 Tage', nb_dauer(48 * 3600), 'volle Tage');

    t_eq('', nb_render_zeitbalken($jetzt - 3600, null, $jetzt), 'ohne Ende kein Balken');
    t_contains('<div class="zeitbalken" aria-hidden="true"><div class="bar__fill" data-w="25"></div></div>', nb_render_zeitbalken($jetzt - 3600, $jetzt + 3 * 3600, $jetzt), 'ein Viertel vorbei');
    t_contains('<div class="zeitbalken zeitbalken--ueber" aria-hidden="true"><div class="bar__fill" data-w="100"></div></div>', nb_render_zeitbalken($jetzt - 3600, $jetzt - 600, $jetzt), 'Ende ueberschritten: voll und gelb');
}

function test_incident_zeitraum_abgelaufen()
{
    $s = ['key' => 'X', 'titel' => 'Störung', 'ki_titel' => 'Test', 'ki_text' => 'Text', 'beginn' => gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 3600), 'ende' => gmdate('Y-m-d\TH:i:s\Z', time() + 6 * 3600)];
    $html = nb_render_incident($s);
    t_contains('<p class="zeitraum">Seit ', $html, 'Zeitraum als Satz');
    t_contains('(2 Std.) · voraussichtlich bis ', $html, 'Dauer und Ende im Satz');
    t_contains('<div class="zeitbalken" aria-hidden="true"><div class="bar__fill" data-w="25"></div></div>', $html, 'Zeitbalken auf der Karte');
    t_eq(false, strpos($html, 'incident__meta'), 'mit KI-Text keine Detailzeilen');
    $abgelaufen = nb_render_incident($s + ['abgelaufen' => true]);
    t_contains('(2 Std.) · länger als gemeldet</p>', $abgelaufen, 'abgelaufen laut Flag, Ende liegt noch vorn: ohne Uhrzeit');
    t_contains('länger als gemeldet, Ende war ', nb_render_incident(['ende' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 600)] + $s), 'Ende vorbei: mit Uhrzeit');
    t_contains('zeitbalken zeitbalken--ueber', $abgelaufen, 'Balken voll und gelb');
    t_contains('<dl class="incident__meta"><div><dt>Auswirkung</dt>', nb_render_incident(['key' => 'Y', 'titel' => 'Roh', 'wirkungen' => ['Verspätungen']]), 'ohne KI-Text die Detailzeilen');
    t_eq(false, strpos(nb_render_incident($s), 'data-weg-id'), 'Karte ohne Flag ohne Knopf');
    $karte = nb_render_incident($s, true);
    t_contains('<li class="card incident" data-weg-id="st:X">', $karte, 'Karte traegt die Kennung der Stoerung');
    t_contains('<button type="button" class="incident__weg" aria-label="Störung ausblenden" title="Diese Meldung f&uuml;r mich ausblenden" hidden>&times;</button></li>', $karte, 'Knopf zum Ausblenden auf der Karte');
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

function test_listen_nach_angezeigter_zeit()
{
    // Eilmeldung spaeter erfasst, aber mit aelterem Nordbahn-Stand: die Anzeige folgt dem Stand
    $items = nb_items([
        'eilmeldungen' => [
            ['id' => 'a', 'title' => 'Alt im Stand', 'seen_at' => '2026-10-16T09:00:00Z'],
            ['id' => 'b', 'title' => 'Neu im Stand', 'seen_at' => '2026-10-16T08:00:00Z'],
        ],
        'eilmeldungen_live' => [
            ['id' => 'a', 'title' => 'Alt im Stand', 'stand' => '2026-10-16T06:00:00Z', 'link' => 'https://x/a'],
            ['id' => 'b', 'title' => 'Neu im Stand', 'stand' => '2026-10-16T08:30:00Z', 'link' => 'https://x/b'],
        ],
    ]);
    t_eq(['a', 'b'], array_column($items, 'id'), 'aelteste zuerst nach Stand');
    $html = nb_render_feed($items, 5, 0, strtotime('2026-10-16 12:00:00 Europe/Berlin'));
    t_eq(true, strpos($html, 'Neu im Stand') < strpos($html, 'Alt im Stand'), 'Liste zeigt die juengste zuerst');

    // Stoerungen: Beginn entscheidet, nicht das Ende
    $v = nb_verlauf_items(['stoerungen' => [], 'stoerungen_verlauf' => [
        ['key' => 'FRUEH_LANG', 'titel' => 'Früh begonnen, spät beendet', 'first_seen' => '2026-10-15T03:00:00Z', 'last_seen' => '2026-10-15T14:43:00Z'],
        ['key' => 'SPAET_KURZ', 'titel' => 'Später begonnen, früh beendet', 'first_seen' => '2026-10-15T03:05:00Z', 'last_seen' => '2026-10-15T05:59:00Z'],
    ]]);
    t_eq(['SPAET_KURZ', 'FRUEH_LANG'], array_column($v, 'key'), 'juengster Beginn zuerst');
}

function test_feed_tag_heute_gestern()
{
    $now = strtotime('2026-10-16 10:00:00 Europe/Berlin');
    t_eq('Heute', nb_fmt_tag(strtotime('2026-10-16 00:10:00 Europe/Berlin'), $now), 'kurz nach Mitternacht ist heute');
    t_eq('Gestern', nb_fmt_tag(strtotime('2026-10-15 23:50:00 Europe/Berlin'), $now), 'kurz vor Mitternacht ist gestern');
    t_eq('14.10.', nb_fmt_tag(strtotime('2026-10-14 12:00:00 Europe/Berlin'), $now), 'aelter mit Datum');
    t_eq('Heute, 08:50 Uhr', nb_fmt_wann(strtotime('2026-10-16 08:50:00 Europe/Berlin'), $now), 'mit Uhrzeit');
    t_eq('bis 07:47 Uhr', nb_fmt_bis(strtotime('2026-10-16 04:52:00 Europe/Berlin'), strtotime('2026-10-16 07:47:00 Europe/Berlin'), $now), 'Ende am selben Tag ohne Datum');
    t_eq('bis heute, 01:10 Uhr', nb_fmt_bis(strtotime('2026-10-15 23:00:00 Europe/Berlin'), strtotime('2026-10-16 01:10:00 Europe/Berlin'), $now), 'Ende ueber Mitternacht mit Tag');
    t_eq('bis 14.10., 00:32 Uhr', nb_fmt_bis(strtotime('2026-10-13 23:30:00 Europe/Berlin'), strtotime('2026-10-14 00:32:00 Europe/Berlin'), $now), 'alte Stoerung ueber Mitternacht mit Datum');
    // DST-Wechsel: Tagesgrenze in Berlin, nicht UTC
    $dst = strtotime('2026-03-29 00:30:00 Europe/Berlin');
    t_eq('Gestern', nb_fmt_tag(strtotime('2026-03-28 23:30:00 Europe/Berlin'), $dst), 'gestern ueber den Zeitumstellungstag');
}

function test_feed_render_heute_und_ki_text()
{
    $now = strtotime('2026-10-16 10:00:00 Europe/Berlin');
    $data = ['eilmeldungen' => [
        ['id' => 'alt', 'title' => 'Zugausfall RB60', 'seen_at' => '2026-10-15T09:45:00Z'],
        ['id' => 'neu', 'title' => 'Zugausfall RB60', 'seen_at' => '2026-10-16T06:50:00Z', 'ki_titel' => 'RB60 ab Altona: 12:22 Ausfall', 'ki_text' => 'Die RB60 um 12:22 ab Hamburg-Altona fällt wegen eines Stellwerkdefekts aus.'],
    ]];
    $items = nb_items($data);
    t_eq('Die RB60 um 12:22 ab Hamburg-Altona fällt wegen eines Stellwerkdefekts aus.', $items[1]['text'], 'KI-Text im Item');
    t_eq('', $items[0]['text'], 'ohne KI-Text leer');
    $html = nb_render_feed($items, 6, 3, $now);
    t_contains('<li class="is-heute"><div class="feed__time">Heute, 08:50 Uhr</div>', $html, 'heutiger Eintrag markiert');
    t_contains('<li><div class="feed__time">Gestern, 11:45 Uhr</div>', $html, 'gestriger Eintrag ohne Markierung');
    t_contains('<p class="feed__meta">Die RB60 um 12:22 ab Hamburg-Altona fällt wegen eines Stellwerkdefekts aus.</p>', $html, 'KI-Text unter dem Titel');
    t_eq(1, substr_count($html, 'feed__meta'), 'kein Meta ohne KI-Text');
    t_contains('Keine Eilmeldung in den letzten 3 Tagen.', nb_render_feed($items, 6, 3, $now + 5 * 86400), 'Altersgrenze mit now');

    $verlauf = nb_verlauf_items(['stoerungen' => [], 'stoerungen_verlauf' => [
        ['key' => 'A', 'titel' => 'Störungen in Eidelstedt', 'first_seen' => '2026-10-16T02:52:00Z', 'last_seen' => '2026-10-16T05:47:00Z'],
        ['key' => 'B', 'titel' => 'Personenunfall', 'first_seen' => '2026-10-13T06:11:00Z', 'last_seen' => '2026-10-13T08:42:00Z'],
    ]]);
    $html = nb_render_verlauf($verlauf, 6, 7, $now);
    t_contains('<li class="is-heute"><div class="feed__time">Heute, 04:52 Uhr</div>', $html, 'heute aufgehoben');
    t_contains('bis 07:47 Uhr · 2 Std. 55 Min.', $html, 'Ende am selben Tag nur Uhrzeit');
    t_contains('<li><div class="feed__time">13.10., 08:11 Uhr</div>', $html, 'alte Stoerung mit Datum');
}

function test_archiv_eilmeldungen_nach_tagen()
{
    $now = strtotime('2026-10-16 10:00:00 Europe/Berlin');
    $items = nb_archiv_eil(['eilmeldungen' => [
        ['id' => 'a', 'title' => 'Alt', 'seen_at' => '2026-10-13T06:00:00Z'],
        ['id' => 'b', 'title' => 'Zugausfall RB60', 'seen_at' => '2026-10-16T06:50:00Z', 'ki_titel' => 'RB60 12:22 fällt aus', 'ki_text' => 'Stellwerkdefekt.'],
        ['id' => 'c', 'title' => 'Gestern früh', 'seen_at' => '2026-10-15T05:00:00Z'],
        ['id' => 'd', 'title' => 'Gestern spät', 'seen_at' => '2026-10-15T20:00:00Z'],
    ]]);
    $html = nb_render_archiv_eil($items, $now);
    t_eq(3, substr_count($html, '<section'), 'drei Tage, drei Abschnitte');
    t_eq(['Heute, 16.10.2026', 'Gestern, 15.10.2026', 'Dienstag, 13.10.2026'], t_titles($html), 'Reihenfolge und Tagesnamen');
    t_contains('<li id="b" class="is-heute"><div class="feed__time">08:50 Uhr</div>', $html, 'heute nur Uhrzeit, markiert, Anker aus der id');
    t_contains('<p class="feed__meta">Stellwerkdefekt.</p>', $html, 'KI-Text');
    t_eq(strpos($html, 'Gestern spät') < strpos($html, 'Gestern früh'), true, 'innerhalb eines Tages juengste zuerst');
    t_contains('Noch keine Eilmeldung erfasst.', nb_render_archiv_eil([], $now), 'leer');
}

function test_archiv_stoerungen_mit_aktiven()
{
    $now = strtotime('2026-10-16 10:00:00 Europe/Berlin');
    $data = ['stoerungen' => [['key' => 'AKTIV']], 'stoerungen_verlauf' => [
        ['key' => 'AKTIV', 'titel' => 'Weichenstörung', 'first_seen' => '2026-10-16T06:00:00Z', 'last_seen' => '2026-10-16T07:58:00Z'],
        ['key' => 'A', 'titel' => 'Signalstörung', 'ki_titel' => 'Störungen in Eidelstedt', 'ki_text' => 'Signal kaputt.', 'first_seen' => '2026-10-16T02:52:00Z', 'last_seen' => '2026-10-16T05:47:00Z'],
        ['key' => 'B', 'titel' => 'Personenunfall', 'first_seen' => '2026-10-13T06:11:00Z', 'last_seen' => '2026-10-13T08:42:00Z'],
    ]];
    $items = nb_archiv_stoerungen($data);
    t_eq(3, count($items), 'aktive sind dabei');
    t_eq(2, count(nb_verlauf_items($data)), 'ohne Schalter wie bisher ohne aktive');
    $html = nb_render_archiv_stoerungen($items, $now);
    t_eq(['Heute, 16.10.2026', 'Dienstag, 13.10.2026'], t_titles($html), 'nach Beginn gruppiert');
    t_eq(strpos($html, 'Weichenstörung') < strpos($html, 'Störungen in Eidelstedt'), true, 'juengster Beginn zuerst');
    t_contains('<a href="./stoerung/AKTIV/weichenstoerung/">Weichenstörung</a> <span class="dep__ab dep__ab--aktiv">aktiv</span>', $html, 'aktiv markiert, Titel auf die Ereignisseite verlinkt');
    t_contains('<li id="AKTIV" class="is-heute">', $html, 'Anker aus dem Key');
    t_eq('BZI_33475', nb_anker('BZI_33475'), 'Key bleibt');
    t_eq('a-b-c', nb_anker('a b/c'), 'Sonderzeichen werden Bindestrich');
    t_contains('dauert an · bisher 2 Std.', $html, 'laufende Dauer bis jetzt');
    t_contains('bis 07:47 Uhr · 2 Std. 55 Min.<br>Signal kaputt.', $html, 'aufgehobene mit Text');
    t_contains('<div class="feed__time">08:11 Uhr</div>', $html, 'alte mit Uhrzeit');
    t_contains('Noch keine Infrastrukturstörung erfasst.', nb_render_archiv_stoerungen([], $now), 'leer');
}

function test_archiv_seiten()
{
    $items = range(1, 120);
    $s = nb_seite($items, 50, 1);
    t_eq([1, 3, 120, 50], [$s['seite'], $s['seiten'], $s['gesamt'], count($s['items'])], 'erste Seite');
    t_eq([101, 120], [nb_seite($items, 50, 3)['items'][0], end(nb_seite($items, 50, 3)['items'])], 'letzte Seite mit Rest');
    t_eq(3, nb_seite($items, 50, 99)['seite'], 'zu grosse Seite wird begrenzt');
    t_eq(1, nb_seite($items, 50, 'x')['seite'], 'Unsinn wird Seite 1');
    t_eq([1, 1, 0], [nb_seite([], 50, 4)['seite'], nb_seite([], 50, 4)['seiten'], count(nb_seite([], 50, 4)['items'])], 'leer');
    t_eq('', nb_render_seitennav(nb_seite(range(1, 10), 50, 1), './'), 'eine Seite: keine Navigation');
    $html = nb_render_seitennav(nb_seite($items, 50, 2), './');
    t_contains('<a class="pager__link" href="./" rel="prev">', $html, 'Neuere fuehrt auf Seite 1 ohne Parameter');
    t_contains('<a class="pager__link" href="./?seite=3" rel="next">', $html, 'Aeltere');
    t_contains('Seite 2 von 3', $html, 'Position');
    $html = nb_render_seitennav(nb_seite($items, 50, 3), './');
    t_eq(false, strpos($html, 'rel="next"') !== false, 'letzte Seite ohne Aeltere-Link');
    t_contains('href="./?seite=2" rel="prev"', $html, 'Neuere auf Seite 2');
}

function test_archiv_seiten_nach_ganzen_tagen()
{
    // 3 Eintraege je Tag an 5 Tagen, mindestens 4 je Seite: eine Seite endet nie mitten im Tag
    $tag = strtotime('2026-10-15 12:00:00 Europe/Berlin');
    $items = [];
    for ($d = 0; $d < 5; $d++) {
        for ($i = 0; $i < 3; $i++) {
            $items[] = ['ts' => $tag - $d * 86400 - $i * 60];
        }
    }
    $zeit = function ($it) { return $it['ts']; };
    $s = nb_seite($items, 4, 1, $zeit);
    t_eq([1, 3, 6], [$s['seite'], $s['seiten'], count($s['items'])], 'erste Seite: zwei ganze Tage');
    t_eq(3, count(nb_seite($items, 4, 3, $zeit)['items']), 'letzte Seite: der Rest');
    t_contains('Neuere Meldungen', nb_render_seitennav(nb_seite($items, 4, 2, $zeit), './'), 'Beschriftung');
}

function test_meldungen_tabs_und_kopf()
{
    $html = nb_render_meldungen_tabs('../', 'eilmeldungen');
    t_contains('<nav class="tabs" aria-label="Meldungen filtern"><a class="tabs__tab" href="../aktuell/">Aktiv</a><a class="tabs__tab" href="../news/">Wichtig</a>', $html, 'Aktiv vor Wichtig');
    t_contains('<a class="tabs__tab" href="../eilmeldungen/" aria-current="page">Eilmeldungen</a>', $html, 'aktiver Tab');
    t_contains('<a class="tabs__tab" href="../aktuell/" aria-current="page">Aktiv</a>', nb_render_meldungen_tabs('../', 'aktuell'), 'Aktiv als aktiver Tab');
    $kopf = nb_render_kopf('../', 'eilmeldungen');
    t_contains('<a class="site-nav__link" href="../news/" aria-current="page">Meldungen</a>', $kopf, 'Meldungen aktiv auf Unterseiten');
    t_contains('<a class="site-nav__link" href="../news/" aria-current="page">Meldungen</a>', nb_render_kopf('../', 'aktuell'), 'Meldungen aktiv auf /aktuell/');

    $karte = nb_render_status_card('status-infra', ['level' => 'warn', 'title' => '2 leichte Infrastrukturstörungen', 'detail' => ''], false, './aktuell/');
    t_contains('<a class="card status status--warn" id="status-infra" href="./aktuell/" data-ssr>', $karte, 'Lagekarte als Link');
    t_contains('</h2></a>', $karte, 'Link sauber geschlossen');
    t_contains('<div class="card status status--ok" id="status-betrieb" data-ssr hidden>', nb_render_status_card('status-betrieb', ['level' => 'ok', 'title' => 'ok', 'detail' => ''], true), 'Lagekarte ohne Link bleibt ein div');
    t_contains('<a class="nav-menu__link nav-menu__link--klein" href="../faq/">', $kopf, 'FAQ im Handy-Menue');
    t_contains('href="../">Start</a>', $kopf, 'Link zur Startseite');
}

/* ---------- Lagekarte Infrastruktur nennt die Stoerung ---------- */

function test_status_infra_nennt_die_stoerung()
{
    $jetzt = nb_ts(T_NOW); // 15.10.2026, 10:00 Uhr
    $st = ['key' => 'A', 'titel' => 'Störung an Signalanlagen', 'ki_titel' => 'Signalstörung bei Tornesch', 'einschraenkung' => 'mittel', 'wirkungen' => ['Verspätungen'], 'ki_folgen' => 'verspaetungen',
        'beginn' => '2026-10-15T06:30:00Z', 'ende' => '2026-10-15T09:30:00Z'];
    $data = ['received_at' => T_NOW, 'quellen' => ['strecken_info_ok' => true], 'stoerungen' => [$st]];
    t_eq(['level' => 'alert', 'title' => '1 Infrastrukturstörung', 'detail' => 'Signalstörung bei Tornesch · voraussichtlich bis 11:30 Uhr'], nb_status_infra($data, $jetzt), 'eine Stoerung: Pendler-Titel und gemeldetes Ende');

    t_eq('voraussichtlich bis morgen 11:30 Uhr', nb_stoerung_kurzzeit(['ende' => '2026-10-16T09:30:00Z'] + $st, $jetzt), 'Ende morgen');
    t_eq('länger als gemeldet', nb_stoerung_kurzzeit(['ende' => '2026-10-15T07:30:00Z'] + $st, $jetzt), 'gemeldetes Ende vorbei');
    t_eq('länger als gemeldet', nb_stoerung_kurzzeit(['abgelaufen' => true] + $st, $jetzt), 'Flag von strecken-info, Ende noch vorn');
    t_eq('seit 08:30 Uhr', nb_stoerung_kurzzeit(['ende' => ''] + $st, $jetzt), 'ohne Ende der Beginn');
    t_eq('seit gestern 08:30 Uhr', nb_stoerung_kurzzeit(['beginn' => '2026-10-14T06:30:00Z', 'ende' => ''] + $st, $jetzt), 'Beginn gestern');
    t_eq('seit 08:30 Uhr', nb_stoerung_kurzzeit(['ende' => '2026-10-15T06:00:00Z'] + $st, $jetzt), 'Ende vor dem Beginn zaehlt nicht');
    t_eq('', nb_stoerung_kurzzeit(['key' => 'X'], $jetzt), 'ohne Zeiten leer');
    t_eq('Störung an Signalanlagen', nb_stoerung_titel(['ki_titel' => '', 'titel' => ' Störung an Signalanlagen ']), 'ohne Pendler-Titel der Originaltitel');
    t_eq('Störung', nb_stoerung_titel([]), 'ohne Titel ein Platzhalter');

    $zweite = ['key' => 'B', 'titel' => 'Weichenstörung – Elmshorn', 'ki_titel' => '', 'einschraenkung' => 'leicht', 'wirkungen' => ['Verspätungen'], 'ki_folgen' => 'verspaetungen', 'beginn' => '2026-10-15T07:00:00Z'];
    $data['stoerungen'] = [$st, $zweite];
    t_eq(['level' => 'alert', 'title' => '2 Infrastrukturstörungen', 'detail' => 'Signalstörung bei Tornesch · Weichenstörung – Elmshorn'], nb_status_infra($data, $jetzt), 'mehrere: nur die Titel');
    $data['stoerungen'] = [];
    t_eq(['level' => 'ok', 'title' => 'Keine Infrastrukturstörung', 'detail' => ''], nb_status_infra($data, $jetzt), 'ohne Stoerung unveraendert');
}

/** Alle section__title-Texte eines HTML-Schnipsels. */
function t_titles($html)
{
    preg_match_all('~<h2 class="section__title">([^<]*)</h2>~', $html, $m);

    return $m[1];
}

function test_lage_markdown()
{
    $now = strtotime('2026-10-16 10:00:00 Europe/Berlin');
    $data = nordbahn_clean_payload([
        'stoerungen' => [['key' => 'X', 'titel' => 'Störung an Signalanlagen', 'text' => 'ggl mit zs 6', 'ki_titel' => 'Verspätungen bei Eidelstedt', 'ki_text' => 'Signalstörung, Züge fahren auf dem Gegengleis.', 'beginn' => '2026-10-16T05:00:00Z', 'ende' => '2026-10-16T07:00:00Z', 'abgelaufen' => true, 'wirkungen' => ['Verspätungen']]],
        'eilmeldungen' => [['id' => 'em-1', 'title' => 'Zugausfall RB60', 'seen_at' => '2026-10-16T06:50:00Z', 'ki_titel' => 'RB60 12:22 ab Altona fällt aus', 'ki_text' => 'Stellwerkdefekt.']],
        'eilmeldungen_live' => [['id' => 'em-1', 'title' => 'Zugausfall RB60', 'stand' => '2026-10-16T06:50:00Z', 'link' => 'https://www.nordbahn.de/x/']],
    ]);
    $data['received_at'] = '2026-10-16T07:58:00Z';
    $data['stoerungen_verlauf'] = [
        ['key' => 'ALT', 'titel' => 'Weichenstörung', 'first_seen' => '2026-10-15T08:00:00Z', 'last_seen' => '2026-10-15T09:30:00Z'],
        ['key' => 'ZUALT', 'titel' => 'Vorgestern', 'first_seen' => '2026-10-14T08:00:00Z', 'last_seen' => '2026-10-14T09:30:00Z'],
    ];
    $abf = ['ok' => true, 'fetched_at' => '2026-10-16T07:59:00Z', 'richtungen' => [
        'hin' => [
            ['zug' => '1', 'zeit' => '2026-10-16T08:12:00+02:00', 'verspaetung' => 3, 'linie' => 'RB61', 'ziel' => 'Hamburg Hbf', 'gleis' => '2', 'gleis_plan' => '1', 'ausfall' => false, 'hinweis' => '', 'ab' => null, 'ersatz' => null, 'teil' => ['art' => 'endet', 'name' => 'Pinneberg']],
            ['zug' => '2', 'zeit' => '2026-10-16T08:40:00+02:00', 'verspaetung' => 0, 'linie' => 'RB71', 'ziel' => 'Hamburg Hbf', 'gleis' => null, 'gleis_plan' => null, 'ausfall' => true, 'hinweis' => 'Kurzfristiger Personalausfall', 'ab' => null, 'ersatz' => null, 'teil' => null],
        ],
        'rueck' => [
            ['zug' => '3', 'zeit' => '2026-10-16T08:15:00+02:00', 'verspaetung' => 0, 'linie' => 'RB61', 'ziel' => 'Itzehoe', 'gleis' => '12', 'gleis_plan' => '12', 'ausfall' => false, 'hinweis' => '', 'ab' => 'Hbf', 'ersatz' => null, 'teil' => null],
        ],
    ]];
    $md = nb_lage_md($data, $abf, 'APD', $now);
    t_contains("# Bahn-Pinneberg.de: aktuelle Lage", $md, 'Titel');
    t_contains('- Infrastruktur: 1 Infrastrukturstörung (Verspätungen bei Eidelstedt · länger als gemeldet)', $md, 'Statuszeile nennt die Stoerung');
    t_contains("### Verspätungen bei Eidelstedt\n\nSignalstörung, Züge fahren auf dem Gegengleis.", $md, 'Pendler-Titel und -Text');
    t_contains('gemeldetes Ende heute, 09:00 Uhr überschritten, dauert an', $md, 'abgelaufen');
    t_contains('- 08:12 Uhr (+3 min): RB61 nach Hamburg Hbf. Gleis 2 statt 1. nur bis Pinneberg.', $md, 'Abfahrt mit Verspätung, Gleiswechsel, Teilausfall');
    t_contains('- 08:40 Uhr: RB71 nach Hamburg Hbf fällt aus. Kurzfristiger Personalausfall.', $md, 'Ausfall mit Hinweis');
    $busMd = nb_md_zug(['zug' => '9', 'zeit' => '2026-10-16T08:50:00+02:00', 'verspaetung' => 0, 'linie' => 'RB61', 'ziel' => 'Hamburg Hbf', 'bus' => true, 'hinweis' => 'Schienenersatzverkehr mit Bus']);
    t_eq('- 08:50 Uhr: Bus (Schienenersatzverkehr) RB61 nach Hamburg Hbf. Schienenersatzverkehr mit Bus.', $busMd, 'Bus in lage.md');
    t_contains('- 08:15 Uhr ab Hbf: RB61 nach Itzehoe. Gleis 12.', $md, 'Rueckrichtung ab Hbf');
    t_contains('- Heute, 08:50 Uhr: RB60 12:22 ab Altona fällt aus. Stellwerkdefekt. (https://bahn-pinneberg.de/eilmeldung/em-1/rb60-12-22-ab-altona-faellt-aus/)', $md, 'Eilmeldung mit Ereignisseite');
    t_contains('- Seite: https://bahn-pinneberg.de/stoerung/X/verspaetungen-bei-eidelstedt/', $md, 'Stoerung mit Ereignisseite');
    t_contains('- Gestern, 10:00 Uhr bis 11:30 Uhr (1 Std. 30 Min.): Weichenstörung', $md, 'aufgehobene Stoerung der letzten 24 Stunden');
    t_eq(false, strpos($md, 'Vorgestern'), 'aeltere aufgehobene Stoerung fehlt');
    t_contains('/llms.txt', $md, 'Verweis auf llms.txt');

    $leer = nb_lage_md(['received_at' => null, 'stoerungen' => [], 'eilmeldungen' => [], 'eilmeldungen_live' => [], 'stoerungen_verlauf' => []], null, 'AEL', $now);
    t_contains('## Nächste Züge ab Elmshorn', $leer, 'Bahnhofsname');
    t_contains('Keine aktuellen Abfahrtsdaten.', $leer, 'ohne Abfahrten');
    t_contains('Keine aktive Störung laut strecken-info.de.', $leer, 'ohne Stoerung');
    t_contains('Keine Eilmeldung in den letzten 24 Stunden.', $leer, 'ohne Eilmeldung');
}

/* ---------- Ankunft je Zug aus dem Laufweg ---------- */

function test_ankunft_aus_dem_laufweg()
{
    $halt = function ($name, $zeit, array $o = []) {
        return $o + ['id' => '', 'name' => $name, 'zeit' => $zeit, 'ist' => null, 'verspaetung' => 0, 'gleis' => '', 'ausfall' => false, 'heimat' => false];
    };
    // Richtung Hamburg: der letzte Halt, den der Zug bedient
    $hin = ['ab' => null, 'ausfall' => false, 'lauf' => [
        $halt('Prisdorf', '10:18', ['heimat' => true]), $halt('Pinneberg', '10:21'), $halt('Hamburg Dammtor', '10:37'), $halt('Hamburg Hbf', '10:41'),
    ]];
    t_eq(['name' => 'Hamburg Hbf', 'zeit' => '10:41', 'ist' => null, 'verspaetung' => 0, 'ausfall' => false], nb_ankunft($hin), 'hin: Ankunft am Hbf');
    $spaet = $hin;
    $spaet['lauf'][3] = $halt('Hamburg Hbf', '10:41', ['ist' => '10:55', 'verspaetung' => 14]);
    t_eq(['name' => 'Hamburg Hbf', 'zeit' => '10:41', 'ist' => '10:55', 'verspaetung' => 14, 'ausfall' => false], nb_ankunft($spaet), 'hin: mit Prognose');
    $kurz = $hin;
    $kurz['lauf'][2]['ausfall'] = true;
    $kurz['lauf'][3]['ausfall'] = true;
    t_eq('Pinneberg', nb_ankunft($kurz)['name'], 'hin: endet der Zug frueher, zaehlt der letzte bediente Halt');
    t_eq(null, nb_ankunft(['ausfall' => true] + $hin), 'Ausfall: keine Ankunft');
    t_eq(null, nb_ankunft(['ab' => null, 'lauf' => null]), 'ohne Laufweg');
    t_eq(null, nb_ankunft(['ab' => null, 'lauf' => [$halt('Prisdorf', '10:18', ['heimat' => true])]]), 'ein Halt ist kein Laufweg');

    // Rueckweg: der Heimatbahnhof, nicht das Ende der Fahrt
    $rueck = ['ab' => 'Hbf', 'ausfall' => false, 'lauf' => [
        $halt('Hamburg Hbf', '10:56'), $halt('Hamburg Dammtor', '10:59'), $halt('Pinneberg', '11:15'), $halt('Prisdorf', '11:19', ['heimat' => true, 'ist' => '11:23', 'verspaetung' => 4]), $halt('Itzehoe', '11:53'),
    ]];
    t_eq(['name' => 'Prisdorf', 'zeit' => '11:19', 'ist' => '11:23', 'verspaetung' => 4, 'ausfall' => false], nb_ankunft($rueck), 'rueck: Ankunft am Heimatbahnhof');
    $durch = $rueck;
    $durch['lauf'][3]['ausfall'] = true;
    t_eq(true, nb_ankunft($durch)['ausfall'], 'rueck: Halt am Heimatbahnhof entfaellt');
    $ohne = $rueck;
    $ohne['lauf'][3]['heimat'] = false;
    t_eq(null, nb_ankunft($ohne), 'rueck: Heimatbahnhof nicht im Laufweg');

    // lage.md und das MCP-Werkzeug abfahrten nennen die Ankunft
    $zeile = ['zug' => '1', 'zeit' => '2026-10-16T10:18:00+02:00', 'verspaetung' => 0, 'linie' => 'RB71', 'ziel' => 'Hamburg Hbf', 'gleis' => '2', 'hinweis' => ''];
    t_eq('- 10:18 Uhr: RB71 nach Hamburg Hbf. Gleis 2. an Hamburg Hbf 10:41 Uhr.', nb_md_zug($zeile + $hin), 'Ankunft in der Markdown-Zeile');
    t_eq('- 10:18 Uhr (+14 min): RB71 nach Hamburg Hbf. Gleis 2. an Hamburg Hbf 10:55 Uhr (+14 min).', nb_md_zug(['verspaetung' => 14] + $zeile + $spaet), 'verspaetete Ankunft');
    $heim = ['zug' => '3', 'zeit' => '2026-10-16T10:56:00+02:00', 'verspaetung' => 0, 'linie' => 'RB61', 'ziel' => 'Itzehoe', 'gleis' => '13', 'hinweis' => ''];
    t_eq('- 10:56 Uhr ab Hbf: RB61 nach Itzehoe. Gleis 13. an Prisdorf 11:23 Uhr (+4 min).', nb_md_zug($heim + $rueck), 'Rueckweg: Ankunft zu Hause');
    t_eq('- 10:56 Uhr ab Hbf: RB61 nach Itzehoe. Gleis 13. hält heute nicht in Prisdorf.', nb_md_zug($heim + $durch), 'Halt entfaellt');
}

/** Die Zeilen aus abf_build tragen den Laufweg, aus dem die Ankunft kommt. */
function test_ankunft_aus_der_abfahrtstafel()
{
    $r = t_build(t_standard(['zug:81871' => t_lauf_81871(), 'zug:75514' => t_lauf_75514(['AP' => ['an_min' => 6, 'ab_min' => 6]])]));
    t_eq(['name' => 'Hamburg Hbf', 'zeit' => '10:41', 'ist' => null, 'verspaetung' => 0, 'ausfall' => false], nb_ankunft(t_zeile($r, 'hin', '81871')), 'hin aus der Zugauskunft');
    t_eq('Prisdorf', nb_ankunft(t_zeile($r, 'rueck', '75514'))['name'], 'rueck: der Heimatbahnhof');
    t_eq('11:19', nb_ankunft(t_zeile($r, 'rueck', '75514'))['zeit'], 'rueck: Planankunft zu Hause');
    t_reset(); // die Zugauskunft von eben liegt sonst noch fuenf Minuten im Cache
    $kurz = t_build(t_standard(['zug:81871' => t_lauf_81871(['ADF' => ['ausfall' => true], 'AH' => ['ausfall' => true]])]));
    t_eq('Pinneberg', nb_ankunft(t_zeile($kurz, 'hin', '81871'))['name'], 'nur bis Pinneberg: Ankunft dort');
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
    t_eq(2, $s['zeitraum']['n'], 'zwei in den letzten 30 Tagen');
    t_eq(8 * 3600, $s['zeitraum']['dauer'], 'Gesamtdauer');
    t_eq(4 * 3600, $s['zeitraum']['schnitt'], 'Schnitt');
    t_eq('Signal kaputt in Elmshorn', $s['zeitraum']['laengste']['titel'], 'laengste mit KI-Titel');
    t_eq(6, count($s['wochen']), '30 Tage: sechs Kalenderwochen');
    t_eq(1, end($s['wochen'])['n'], 'laufende Woche zuletzt, mit der Stoerung von vorgestern');
    t_eq(['Störung an Signalanlagen'], array_column($s['ursachen'], 'name'), 'Ursachen im Zeitraum, Unterursache abgeschnitten');
    t_eq(2, $s['ursachen'][0]['n'], 'zwei Signalstoerungen');
    $s = nb_statistik($verlauf, $now, 60);
    t_eq(3, $s['zeitraum']['n'], '60 Tage: auch die Bauarbeiten');
    t_eq(['Störung an Signalanlagen', 'Bauarbeiten'], array_column($s['ursachen'], 'name'), 'Ursachen der 60 Tage, haeufigste zuerst');
    t_eq(10, count($s['wochen']), '60 Tage: zehn Kalenderwochen');
    t_eq(2, count(nb_statistik($verlauf, $now, 7)['wochen']), '7 Tage: zwei Kalenderwochen');
    t_eq(0, nb_bar_stufe(0, 5), 'leerer Balken');
    t_eq(100, nb_bar_stufe(5, 5), 'voller Balken');
    t_eq(5, nb_bar_stufe(1, 100), 'mindestens 5 Prozent, wenn ueberhaupt etwas da ist');
    t_eq(40, nb_bar_stufe(2, 5), 'gerundet auf 5er-Stufe');
}

function test_wichtig_regeln()
{
    t_eq(true, nb_wichtig_stoerung(['einschraenkung' => 'schwer'], 0, 60), 'schwere Einschraenkung');
    t_eq(true, nb_wichtig_stoerung(['einschraenkung' => 'leicht', 'wirkungen' => ['Züge werden zurückgehalten', 'Ausfall']], 0, 60), 'Ausfall als Wirkung');
    t_eq(false, nb_wichtig_stoerung(['einschraenkung' => 'leicht', 'wirkungen' => ['Verspätungen']], 0, 3600), 'nur Verspaetungen, eine Stunde');
    t_eq(true, nb_wichtig_stoerung(['einschraenkung' => 'leicht', 'wirkungen' => ['Verspätungen']], 0, 2 * 3600), 'ab zwei Stunden Dauer');
    t_eq(false, nb_wichtig_stoerung(['einschraenkung' => 'schwer', 'wichtig' => false], 0, 60), 'explizites Feld gewinnt');

    t_eq(false, nb_wichtig_eilmeldung('05:17 Elmshorn – Altona fällt aus (kurzfristiger Personalausfall)', 'Der Zug NBE81819 ab 05:17 Uhr entfällt. Bitte den Nachfolgetakt NBE 75503 nutzen.', 'Zugausfall RB60'), 'einzelner Zugausfall nicht wichtig');
    t_eq(true, nb_wichtig_eilmeldung('RB61/RB71 bis 06:00 Uhr: Züge enden in Pinneberg (Signalstörung)', 'Weiter mit der S-Bahn.', 'Zugausfall RB61/RB71'), 'Zeitraum und Signalstoerung');
    t_eq(true, nb_wichtig_eilmeldung('', '', 'Ersatzverkehr zwischen Elmshorn und Wrist'), 'Ersatzverkehr im Nordbahn-Titel');
    t_eq(true, nb_wichtig_eilmeldung('RB71 heute: mehrere Züge fallen aus', '', ''), 'mehrere Zuege');
}

function test_wichtig_items_und_startseite()
{
    $jetzt = nb_ts(T_NOW);
    $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
    $data = [
        'stoerungen' => [['key' => 'LAEUFT', 'titel' => 'Signalstörung', 'einschraenkung' => 'schwer', 'wirkungen' => [], 'beginn' => $iso($jetzt - 1800), 'ende' => '', 'ki_titel' => 'Störung in Eidelstedt', 'ki_text' => '']],
        'stoerungen_verlauf' => [
            ['key' => 'LAEUFT', 'titel' => 'Signalstörung', 'einschraenkung' => 'schwer', 'first_seen' => $iso($jetzt - 1800), 'last_seen' => $iso($jetzt), 'ki_titel' => 'Störung in Eidelstedt'],
            ['key' => 'GROSS', 'titel' => 'Weichenstörung', 'einschraenkung' => 'leicht', 'wirkungen' => ['Ausfall'], 'first_seen' => $iso($jetzt - 7200), 'last_seen' => $iso($jetzt - 3600), 'ki_titel' => 'Ausfälle bei Tornesch', 'ki_text' => 'Weiche klemmte.'],
            ['key' => 'KLEIN', 'titel' => 'Weichenstörung', 'einschraenkung' => 'leicht', 'wirkungen' => ['Verspätungen'], 'first_seen' => $iso($jetzt - 5400), 'last_seen' => $iso($jetzt - 5000), 'ki_titel' => 'Kurze Verspätungen'],
            ['key' => 'ALT', 'titel' => 'Oberleitung', 'einschraenkung' => 'schwer', 'first_seen' => $iso($jetzt - 3 * 86400), 'last_seen' => $iso($jetzt - 3 * 86400 + 3600), 'ki_titel' => 'Oberleitungsschaden'],
        ],
        'eilmeldungen_live' => [],
        'eilmeldungen' => [
            ['id' => 'em-1', 'title' => 'Zugausfall RB61', 'seen_at' => $iso($jetzt - 600), 'ki_titel' => 'RB61 7:42 fällt aus (kurzfristiger Personalausfall)', 'ki_text' => 'Nächster Zug 8:12.'],
            ['id' => 'em-2', 'title' => 'Ersatzverkehr RB71', 'seen_at' => $iso($jetzt - 900), 'ki_titel' => 'RB71: Ersatzverkehr Elmshorn – Wrist bis 18 Uhr', 'ki_text' => 'Busse fahren ab Bahnhofsvorplatz.'],
        ],
    ];
    $items = nb_wichtig_items($data, $jetzt);
    t_eq(['em-2', 'LAEUFT', 'GROSS', 'ALT'], array_map(function ($it) { return $it['art'] === 'stoerung' ? $it['key'] : $it['id']; }, $items), 'wichtige Meldungen, juengste zuerst, ohne kleine Stoerung und einzelnen Ausfall');
    t_eq(true, $items[1]['aktiv'], 'laufende Stoerung markiert');

    $html = nb_render_wichtig_eintrag($items[0], $jetzt);
    t_contains('<p class="feed__kicker feed__kicker--eilmeldung">Eilmeldung</p><p class="feed__title"><a href="./eilmeldung/em-2/rb71-ersatzverkehr-elmshorn-wrist-bis-18-uhr/">RB71: Ersatzverkehr Elmshorn – Wrist bis 18 Uhr</a></p>', $html, 'Eilmeldung mit Rubrik und Link');
    t_contains('<p class="feed__kicker feed__kicker--stoerung">St&ouml;rung</p><p class="feed__title"><a href="./stoerung/GROSS/ausfaelle-bei-tornesch/">Ausfälle bei Tornesch</a></p><p class="feed__meta">bis 09:00 Uhr · 1 Std.<br>Weiche klemmte.</p>', nb_render_wichtig_eintrag($items[2], $jetzt), 'Stoerung mit Ende, Dauer und Text');

    $markiert = nb_wichtig_markieren($data, $jetzt);
    t_eq(true, $markiert['stoerungen'][0]['wichtig'], 'laufende Stoerung markiert');
    t_eq(false, $markiert['stoerungen_verlauf'][2]['wichtig'], 'kleine Stoerung nicht markiert');
    t_eq([false, true], array_column($markiert['eilmeldungen'], 'wichtig'), 'Eilmeldungen markiert');

    $news = nb_render_news(array_slice($items, 0, 2), $jetzt);
    t_contains('<h2 class="section__title">Heute, 15.10.2026</h2>', $news, 'Tagesabschnitt');
    t_contains('St&ouml;rung <span class="dep__ab dep__ab--aktiv">aktiv</span>', $news, 'laufende Stoerung in der Rubrikzeile');
}

function test_aktuelle_meldungen()
{
    $jetzt = nb_ts(T_NOW);
    $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
    $data = [
        'stoerungen' => [['key' => 'LAEUFT', 'titel' => 'Signalstörung', 'beginn' => $iso($jetzt - 1800)]],
        'stoerungen_verlauf' => [
            ['key' => 'LAEUFT', 'titel' => 'Signalstörung', 'first_seen' => $iso($jetzt - 1800), 'last_seen' => $iso($jetzt)],
            ['key' => 'GERADE', 'titel' => 'Weichenstörung', 'first_seen' => $iso($jetzt - 5400), 'last_seen' => $iso($jetzt - 1200), 'ki_titel' => 'Verspätungen bei Tornesch'],
            ['key' => 'GESTERN', 'titel' => 'Oberleitung', 'first_seen' => $iso($jetzt - 86400), 'last_seen' => $iso($jetzt - 80000)],
        ],
        'eilmeldungen_live' => [],
        'eilmeldungen' => [
            ['id' => 'em-neu', 'title' => 'Zugausfall RB61', 'seen_at' => $iso($jetzt - 600), 'ki_titel' => 'RB61 7:42 fällt aus'],
            ['id' => 'em-alt', 'title' => 'Zugausfall RB71', 'seen_at' => $iso($jetzt - 5 * 3600)],
        ],
    ];
    $hvv = [
        ['id' => 'U', 'titel' => 'S3: Signalstörung', 'text' => '', 'geplant' => false, 'aktiv' => true, 'von' => $iso($jetzt - 300), 'bis' => $iso($jetzt + 3600), 'linien' => ['S3']],
        ['id' => 'P1', 'titel' => 'Hohes Fahrgastaufkommen', 'text' => '', 'geplant' => true, 'aktiv' => true, 'von' => $iso($jetzt - 86400), 'bis' => $iso($jetzt + 2 * 86400), 'linien' => ['S3', 'S5']],
        ['id' => 'P2', 'titel' => 'Hohes Fahrgastaufkommen erwartet', 'text' => '', 'geplant' => true, 'aktiv' => true, 'von' => $iso($jetzt - 86400), 'bis' => $iso($jetzt + 2 * 86400), 'linien' => ['S3', 'S5']],
        ['id' => 'K', 'titel' => 'Bauarbeiten', 'text' => '', 'geplant' => true, 'aktiv' => false, 'von' => $iso($jetzt + 3 * 86400), 'bis' => $iso($jetzt + 5 * 86400), 'linien' => ['RB61']],
        ['id' => 'X', 'titel' => 'Vorbei', 'text' => '', 'geplant' => false, 'aktiv' => false, 'von' => $iso($jetzt - 7200), 'bis' => $iso($jetzt - 3600), 'linien' => ['S3']],
    ];
    $liste = nb_aktuelle_meldungen($data, $hvv, $jetzt);
    t_eq(['U', 'em-neu', 'GERADE'], array_map(function ($it) { return $it['id'] ?? $it['key'] ?? $it['meldung']['id']; }, $liste), 'aktuelle HVV-Meldung, neue Eilmeldung, gerade aufgehobene Stoerung, juengste zuerst; ohne laufende, alte, geplante und abgelaufene');

    $hvv[] = ['id' => 'U2', 'titel' => 'S3: Signalstörung (Update)', 'text' => '', 'geplant' => false, 'aktiv' => true, 'von' => $iso($jetzt - 300), 'bis' => $iso($jetzt + 3600), 'linien' => ['S3']];
    t_eq(1, substr_count(nb_render_aktuelle_meldungen(nb_aktuelle_meldungen($data, $hvv, $jetzt), $jetzt), 'S3: Signalstörung'), 'Doppelte HVV-Meldung nur einmal');
    $html = nb_render_aktuelle_meldungen($liste, $jetzt);
    t_contains('<ol class="feed" id="meldungen-feed">', $html, 'Liste');
    t_contains('<li class="is-heute" data-weg-id="eil:em-neu">', $html, 'Eilmeldung traegt ihre Kennung zum Ausblenden');
    t_contains('<li class="is-heute" data-weg-id="st:GERADE">', $html, 'Stoerung traegt ihre Kennung zum Ausblenden');
    t_contains('<li class="is-heute" data-weg-id="hvv:U">', $html, 'HVV-Meldung traegt ihre Kennung zum Ausblenden');
    t_contains('</div><button type="button" class="feed__weg" aria-label="Eilmeldung ausblenden" title="Diese Meldung f&uuml;r mich ausblenden" hidden>&times;</button></li>', $html, 'Knopf zum Ausblenden, bis zum Script versteckt');
    t_contains('aria-label="Störung ausblenden"', $html, 'Knopf bei der Stoerung');
    t_contains('aria-label="HVV-Meldung ausblenden"', $html, 'Knopf bei der HVV-Meldung');
    t_eq(3, substr_count($html, 'class="feed__weg"'), 'jede der drei Meldungen hat einen Knopf');
    t_eq(false, strpos(nb_render_news(nb_wichtig_items($data, $jetzt), $jetzt), 'feed__weg'), 'auf /news/ kein Knopf zum Ausblenden');
    t_eq(false, strpos(nb_render_hvv_eintrag($hvv[0], $jetzt), 'feed__weg'), 'HVV-Eintrag ohne Flag ohne Knopf');
    t_eq(false, strpos(nb_render_aktuelle_meldungen($liste, $jetzt, false), 'data-weg-id'), 'auf /aktuell/ ohne Knopf und Kennung');

    $ruhig = nb_aktuelle_meldungen(['stoerungen' => [], 'stoerungen_verlauf' => [], 'eilmeldungen' => [], 'eilmeldungen_live' => []], null, $jetzt);
    t_eq('', nb_render_aktuelle_meldungen($ruhig, $jetzt), 'ruhige Lage: nichts, der Bereich wird ausgeblendet');
    t_eq([], nb_aktuelle_meldungen(['stoerungen' => [], 'stoerungen_verlauf' => [], 'eilmeldungen' => [], 'eilmeldungen_live' => []], array_slice($hvv, 1, 3), $jetzt), 'nur geplante: nichts');
}

function test_eil_karte_hinweis_nur_bis_zwoelf_stunden()
{
    $data = ['received_at' => '2026-10-15T08:00:00Z'];
    $item = function ($alter) { return [['id' => 'em', 'title' => 'Zugausfall', 'ts' => time() - $alter, 'stand' => null]]; };
    t_eq('Letzte vor 6 Std.', nb_status_eil($data, $item(6 * 3600))['detail'], 'sechs Stunden: Hinweis');
    t_eq('', nb_status_eil($data, $item(13 * 3600))['detail'], 'dreizehn Stunden: kein Hinweis');
    t_eq('Keine akuten Eilmeldungen', nb_status_eil($data, $item(13 * 3600))['title'], 'Titel bleibt');
}

function test_eilmeldung_nicht_mehr_auf_nordbahn_de_ist_erledigt()
{
    $jetzt = time();
    $iso = function ($ts) { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
    $data = [
        'received_at'       => $iso($jetzt),
        'quellen'           => ['strecken_info_ok' => true, 'nordbahn_ok' => true],
        'stoerungen'        => [],
        'stoerungen_verlauf' => [],
        'eilmeldungen'      => [
            ['id' => 'em-weg', 'title' => 'RB 71: Streckensperrung Dauenhof - Wrist', 'seen_at' => $iso($jetzt - 2 * 3600)],
            ['id' => 'em-da', 'title' => 'RB61: Zugausfall', 'seen_at' => $iso($jetzt - 3 * 3600)],
        ],
        'eilmeldungen_live' => [['id' => 'em-da', 'title' => 'RB61: Zugausfall', 'stand' => $iso($jetzt - 3 * 3600), 'link' => 'https://www.nordbahn.de/x/']],
    ];
    $ids = function ($d) use ($jetzt) { return array_map(function ($it) { return $it['id']; }, nb_aktuelle_meldungen($d, null, $jetzt)); };
    t_eq(['em-da'], $ids($data), 'von nordbahn.de entfernte Eilmeldung ist nicht mehr aktuell');
    $k = nb_status_eil($data, nb_items($data), $jetzt);
    t_eq('Eilmeldung vor 3 Std.', $k['title'], 'Lagekarte nennt die noch gelistete Meldung');

    $data['eilmeldungen_live'] = [];
    t_eq([], $ids($data), 'nichts mehr gelistet: keine aktuelle Eilmeldung');
    $k = nb_status_eil($data, nb_items($data), $jetzt);
    t_eq(['ok', 'Keine akuten Eilmeldungen', 'Letzte vor 2 Std.'], [$k['level'], $k['title'], $k['detail']], 'Lagekarte gruen mit Hinweis');

    $data['quellen']['nordbahn_ok'] = false;
    t_eq(['em-weg', 'em-da'], $ids($data), 'nordbahn.de nicht lesbar: nur das Alter zaehlt');
}

function test_lagekarten_kompakt()
{
    $ok = function ($detail = '') { return ['level' => 'ok', 'title' => 'x', 'detail' => $detail]; };
    $k = nb_status_kompakt(['infra' => $ok(), 'eil' => $ok('Letzte vor 6 Std.'), 'betrieb' => $ok(), 's3' => $ok()]);
    t_eq(true, $k['alles'], 'alles gruen');
    t_eq('Keine Störung oder Eilmeldung, Nordbahn und S3 planmäßig · letzte Eilmeldung vor 6 Std.', $k['detail'], 'Detail mit letzter Eilmeldung');
    t_eq(['infra' => true, 'eil' => true, 'betrieb' => true, 's3' => true], $k['versteckt'], 'alle Einzelkarten versteckt');
    t_eq('', $k['rest'], 'keine Sammelzeile');

    $k = nb_status_kompakt(['infra' => $ok(), 'eil' => $ok(), 'betrieb' => ['level' => 'alert', 'title' => 'Nordbahn gestört', 'detail' => ''], 's3' => ['level' => 'unknown', 'title' => 'Lade S3 …', 'detail' => '']]);
    t_eq(false, $k['alles'], 'Stoerung: nicht alles gruen');
    t_eq(['infra' => true, 'eil' => true, 'betrieb' => false, 's3' => false], $k['versteckt'], 'gestoerte und unbekannte Karte sichtbar');
    t_eq('Infrastruktur und Eilmeldungen ok', $k['rest'], 'Sammelzeile');

    $k = nb_status_kompakt(['infra' => $ok(), 'eil' => $ok(), 'betrieb' => $ok()]);
    t_eq('Keine Störung oder Eilmeldung, Nordbahn planmäßig', $k['detail'], 'ohne S3');
    t_eq('A, B und C', nb_aufzaehlung(['A', 'B', 'C']), 'Aufzaehlung');
}

function test_statistik_url_und_verlauf()
{
    $f = zl_filter([]) + ['ansicht' => 'zuege', 'sort' => 'zeit'];
    t_eq('./', nb_statistik_url($f), 'Vorgaben: keine Parameter');
    t_eq('./?ansicht=strecke&tage=7', nb_statistik_url($f, ['ansicht' => 'strecke', 'tage' => 7]), 'nur Abweichungen');
    $html = nb_render_zug_verlauf([
        ['tag' => '2026-10-15', 'puenktlich' => 8, 'spaet' => 1, 'ausfall' => 1, 'beobachtet' => true],
        ['tag' => '2026-10-16', 'puenktlich' => 0, 'spaet' => 0, 'ausfall' => 0, 'beobachtet' => false],
    ]);
    t_contains('<div class="verlauf__tag" data-h="100" title="Do 15.10.: 8 pünktlich, 1 verspätet, 1 ausgefallen">', $html, 'Saeule mit Tooltip');
    t_contains('<span class="verlauf__seg verlauf__seg--ausfall" data-h="10"></span>', $html, 'Ausfall unten');
    t_contains('title="Fr 16.10.: nicht beobachtet"', $html, 'Tag ohne Beobachtung');
    t_eq('87 %', nb_prozent(0.866), 'Prozent');
}
