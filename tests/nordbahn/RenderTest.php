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
    t_contains('<a class="tabs__tab" href="../news/">Wichtig</a>', $html, 'Wichtig');
    t_contains('<a class="tabs__tab" href="../eilmeldungen/" aria-current="page">Eilmeldungen</a>', $html, 'aktiver Tab');
    $kopf = nb_render_kopf('../', 'eilmeldungen');
    t_contains('<a class="site-nav__link" href="../news/" aria-current="page">Meldungen</a>', $kopf, 'Meldungen aktiv auf Unterseiten');
    t_contains('<a class="nav-menu__link nav-menu__link--klein" href="../faq/">', $kopf, 'FAQ im Handy-Menue');
    t_contains('href="../">Start</a>', $kopf, 'Link zur Startseite');
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
    t_contains('- Infrastruktur: 1 Infrastrukturstörung', $md, 'Statuszeile');
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
    t_contains('<p class="feed__kicker feed__kicker--eilmeldung">Eilmeldung der Nordbahn</p><p class="feed__title"><a href="./eilmeldung/em-2/rb71-ersatzverkehr-elmshorn-wrist-bis-18-uhr/">RB71: Ersatzverkehr Elmshorn – Wrist bis 18 Uhr</a></p>', $html, 'Eilmeldung mit Rubrik und Link');
    t_contains('<p class="feed__kicker feed__kicker--stoerung">Infrastrukturst&ouml;rung</p><p class="feed__title"><a href="./stoerung/GROSS/ausfaelle-bei-tornesch/">Ausfälle bei Tornesch</a></p><p class="feed__meta">bis 09:00 Uhr · 1 Std.<br>Weiche klemmte.</p>', nb_render_wichtig_eintrag($items[2], $jetzt), 'Stoerung mit Ende, Dauer und Text');

    $markiert = nb_wichtig_markieren($data, $jetzt);
    t_eq(true, $markiert['stoerungen'][0]['wichtig'], 'laufende Stoerung markiert');
    t_eq(false, $markiert['stoerungen_verlauf'][2]['wichtig'], 'kleine Stoerung nicht markiert');
    t_eq([false, true], array_column($markiert['eilmeldungen'], 'wichtig'), 'Eilmeldungen markiert');

    $news = nb_render_news(array_slice($items, 0, 2), $jetzt);
    t_contains('<h2 class="section__title">Heute, 15.10.2026</h2>', $news, 'Tagesabschnitt');
    t_contains('Infrastrukturst&ouml;rung <span class="dep__ab dep__ab--aktiv">aktiv</span>', $news, 'laufende Stoerung in der Rubrikzeile');
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
    t_contains('<ol class="feed" id="meldungen-feed">', nb_render_aktuelle_meldungen($liste, $jetzt), 'Liste');

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
