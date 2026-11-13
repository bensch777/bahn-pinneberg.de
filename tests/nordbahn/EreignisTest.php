<?php
/** Ereignisseiten: Verlauf im Logbuch, Finden, Eilmeldungs-Logbuch, Zeitraeume. */

function test_logbuch_verlauf_schritte()
{
    foreach (glob(NORDBAHN_CACHE_DIR . '/stoerungen-log.json') ?: [] as $f) {
        unlink($f);
    }
    $s = function ($text, $kiText, $ende) {
        return ['key' => 'X', 'titel' => 'Störung an Signalanlagen', 'text' => $text, 'abschnitte' => [], 'wirkungen' => [], 'einschraenkung' => '', 'beginn' => '2026-10-17T01:00:00Z', 'ende' => $ende, 'ki_titel' => $kiText === '' ? '' : 'Störung in Eidelstedt', 'ki_text' => $kiText];
    };
    nordbahn_update_log([$s('Stromlos', '', '2026-10-17T05:00:00Z')], '2026-10-17T01:00:00Z');
    nordbahn_update_log([$s('Stromlos', '', '2026-10-17T05:00:00Z')], '2026-10-17T01:02:00Z'); // nichts neu
    nordbahn_update_log([$s('Stromlos', 'Schwere Störung.', '2026-10-17T05:00:00Z')], '2026-10-17T01:04:00Z'); // Pendler-Text nachgetragen
    nordbahn_update_log([$s('Stromlos. Reparatur läuft.', 'Reparatur läuft.', '2026-10-17T05:00:00Z')], '2026-10-17T03:00:00Z'); // neuer Text
    nordbahn_update_log([$s('Stromlos. Reparatur läuft.', 'Reparatur läuft.', '2026-10-17T10:00:00Z')], '2026-10-17T04:30:00Z'); // neues Ende
    nordbahn_update_log([$s('Stromlos. Reparatur läuft.', 'Reparatur läuft, dauert länger.', '2026-10-17T10:00:00Z')], '2026-10-17T04:35:00Z'); // KI formuliert nach
    $log = nordbahn_read_json(NORDBAHN_LOG_FILE);
    $v = $log['X']['verlauf'];
    t_eq(5, count($v), 'fuenf Staende: neu, KI nachgetragen, Text, Ende, KI neu formuliert');
    t_eq('2026-10-17T01:00:00Z', $v[0]['t'], 'erster Schritt traegt first_seen');
    t_eq('2026-10-17T04:30:00Z', $v[3]['t'], 'letzter Schritt zum Zeitpunkt der Aenderung');

    $schritte = nb_verlauf_schritte($v);
    t_eq(['neu', 'text', 'ende'], array_column($schritte, 'art'), 'nachgetragener oder neu formulierter Pendler-Text ist kein eigener Schritt');
    t_eq('Schwere Störung.', $schritte[0]['ki_text'], 'Pendler-Text im ersten Schritt eingearbeitet');
    t_eq('Reparatur läuft, dauert länger.', $schritte[2]['ki_text'], 'neu formulierter Text im Schritt der Aenderung');
    t_eq(strtotime('2026-10-17T04:30:00Z'), $schritte[2]['ts'], 'Schritt behaelt die Zeit der Aenderung bei strecken-info');
    $jetzt = strtotime('2026-10-17 06:00:00 Europe/Berlin');
    $html = nb_render_verlauf_schritte($schritte, $jetzt);
    t_contains('<span class="zeitleiste__etikett">Gemeldet</span>', $html, 'Etikett neu');
    t_contains('<p class="zeitleiste__hinweis">Voraussichtlich bis 07:00 Uhr</p>', $html, 'erstes gemeldetes Ende beim ersten Schritt');
    t_contains('<span class="zeitleiste__etikett">Update</span>', $html, 'Etikett Text');
    t_contains('<span class="zeitleiste__etikett">Verlängert</span>', $html, 'Etikett Ende');
    t_contains('<p class="zeitleiste__hinweis">Ende: 07:00 Uhr → 12:00 Uhr</p>', $html, 'altes und neues Ende');
    t_eq(false, strpos($html, 'Pendler-Text'), 'kein Schritt "Pendler-Text aktualisiert"');
    t_eq(false, strpos($html, 'zeitleiste__mehr'), 'drei Schritte: nichts eingeklappt');
    t_eq(1, substr_count($html, 'Reparatur läuft, dauert länger.'), 'Text nur im Schritt der Aenderung, nicht wiederholt');

    // Aufgehoben: Abschluss mit Dauer; auch viele Schritte alle sichtbar
    $mit = nb_verlauf_schritte($v, strtotime('2026-10-17T05:10:00Z'));
    t_eq('aufgehoben', end($mit)['art'], 'letzter Schritt Aufhebung');
    t_eq('4 Std. 10 Min.', end($mit)['dauer'], 'Dauer seit dem ersten Schritt');
    $html = nb_render_verlauf_schritte($mit, $jetzt);
    t_contains('<span class="zeitleiste__etikett">Aufgehoben</span>', $html, 'Etikett Aufgehoben');
    t_contains('<p class="zeitleiste__hinweis">Dauer 4 Std. 10 Min.</p>', $html, 'Dauer im letzten Schritt');
    t_eq(false, strpos($html, 'zeitleiste__mehr'), 'vier Schritte: nichts eingeklappt');
    $viele = array_merge($mit, $mit);
    $html = nb_render_verlauf_schritte($viele, $jetzt);
    t_eq(false, strpos($html, 'zeitleiste__mehr'), 'viele Schritte: nichts eingeklappt');
    t_eq(8, substr_count($html, '<li '), 'alle acht Schritte sichtbar');

    // Ereignisseite: Text aus dem Kopf nicht wiederholen, Dauer steht schon im Zeitraum
    $html = nb_render_verlauf_schritte($mit, $jetzt, ['kopf_text' => 'Reparatur läuft, dauert länger.', 'ohne_dauer' => true]);
    t_eq(false, strpos($html, 'Reparatur läuft, dauert länger.'), 'Kopftext nicht im Verlauf wiederholt');
    t_contains('Aktueller Text, siehe oben', $html, 'Schritt verweist auf den Kopf');
    t_contains('Schwere Störung.', $html, 'aelterer Text bleibt im Verlauf');
    t_eq(false, strpos($html, 'Dauer '), 'ohne Dauer');
    unlink(NORDBAHN_LOG_FILE);
}

function test_wirkung_lesbar()
{
    t_eq('Fahrt auf dem Gegengleis', nb_wirkung_lesbar('ggl mit zs 6'), 'Betriebskuerzel Gegengleis');
    t_eq('Verspätungen', nb_wirkung_lesbar('Verspätungen'), 'Klartext bleibt');
    t_eq('Ausfall', nb_wirkung_lesbar('ausfall'), 'erster Buchstabe gross');
}

function test_stoerung_finden_und_zeitraeume()
{
    $data = [
        'received_at' => '2026-10-17T08:00:00Z',
        'stoerungen' => [['key' => 'AKTIV', 'titel' => 'Weichenstörung', 'text' => 'W1 gestört', 'abschnitte' => [], 'wirkungen' => ['Verspätungen'], 'einschraenkung' => 'leicht', 'beginn' => '2026-10-17T06:00:00Z', 'ende' => '2026-10-17T09:00:00Z', 'abgelaufen' => false, 'betriebsstellen' => ['Pinneberg'], 'ki_titel' => 'Verspätungen bei Pinneberg', 'ki_text' => 'Eine Weiche klemmt.']],
        'stoerungen_verlauf' => [
            ['key' => 'AKTIV', 'titel' => 'Weichenstörung', 'text' => 'W1', 'first_seen' => '2026-10-17T06:00:00Z', 'last_seen' => '2026-10-17T07:58:00Z', 'ki_titel' => 'Verspätungen bei Pinneberg', 'ki_text' => 'Eine Weiche klemmt.', 'verlauf' => [['t' => '2026-10-17T06:00:00Z', 'text' => 'W1', 'ki_titel' => '', 'ki_text' => '', 'ende' => '']]],
            ['key' => 'ALT', 'titel' => 'Signalstörung', 'first_seen' => '2026-10-17T05:00:00Z', 'last_seen' => '2026-10-17T06:30:00Z', 'ki_titel' => 'Störung in Eidelstedt', 'ki_text' => ''],
            ['key' => 'FERN', 'titel' => 'Vorgestern', 'first_seen' => '2026-10-15T05:00:00Z', 'last_seen' => '2026-10-15T06:30:00Z'],
        ],
        'eilmeldungen' => [
            ['id' => 'em-1', 'title' => 'Zugausfall RB61', 'seen_at' => '2026-10-17T06:40:00Z', 'ki_titel' => 'RB61 7:42 fällt aus', 'ki_text' => 'Weiche.'],
            ['id' => 'em-2', 'title' => 'Alt', 'seen_at' => '2026-10-16T06:40:00Z'],
        ],
        'eilmeldungen_live' => [],
    ];
    $st = nb_stoerung_finden($data, 'AKTIV');
    t_eq(['Verspätungen bei Pinneberg', true, 'Eine Weiche klemmt.', 'Weichenstörung'], [$st['titel'], $st['aktiv'], $st['ki_text'], $st['titel_technisch']], 'aktive Stoerung mit Live-Stand');
    t_eq(strtotime('2026-10-17T06:00:00Z'), $st['start'], 'Beginn aus first_seen');
    $alt = nb_stoerung_finden($data, 'ALT');
    t_eq([false, 'Störung in Eidelstedt', strtotime('2026-10-17T06:30:00Z')], [$alt['aktiv'], $alt['titel'], $alt['ende']], 'aufgehobene Stoerung aus dem Log');
    t_eq(null, nb_stoerung_finden($data, 'GIBTSNICHT'), 'unbekannt');

    t_eq(['em-1'], array_column(nb_eilmeldungen_im_zeitraum(nb_items($data), $st['start'], $st['ende']), 'id'), 'Eilmeldung im Zeitraum');
    t_eq(['ALT'], array_column(nb_stoerungen_im_zeitraum($data, $st['start'], $st['ende'], 'AKTIV'), 'key'), 'ueberlappende andere Stoerung, nicht die eigene, nicht die alte');
    $em = nb_eilmeldung_finden($data, 'em-1');
    t_eq('RB61 7:42 fällt aus', $em['title'], 'Eilmeldung gefunden');
    t_eq(null, nb_eilmeldung_finden($data, 'em-9'), 'Eilmeldung unbekannt');

    t_eq('./stoerung/BZI_1/', nb_link('stoerung', 'BZI_1'), 'relativer Link ohne Titel');
    t_eq('./stoerung/BZI_1/stoerung-in-hamburg-eidelstedt/', nb_link('stoerung', 'BZI_1', 'Störung in Hamburg-Eidelstedt'), 'relativer Link mit Slug');
    nb_root('../../');
    t_eq('../../eilmeldung/em-1/', nb_link('eilmeldung', 'em-1'), 'relativer Link von einer Unterseite');
    nb_root('./');
    t_eq('https://bahn-pinneberg.de/stoerung/BZI_1/', nb_ereignis_url('stoerung', 'BZI_1'), 'absoluter Link');
    t_eq('https://bahn-pinneberg.de/eilmeldung/em-1/rb61-05-17-elmshorn-altona-faellt-aus-kurzfristiger/', nb_ereignis_url('eilmeldung', 'em-1', 'RB61 05:17 Elmshorn – Altona fällt aus (kurzfristiger Personalausfall)'), 'absoluter Link, Slug an Wortgrenze gekuerzt');
}

function test_slug()
{
    t_eq('rb61-rb71-bis-06-00-uhr-zuege-enden-in-pinneberg', nb_slug('RB61/RB71 bis 06:00 Uhr: Züge enden in Pinneberg'), 'Umlaute, Satzzeichen, Doppelpunkt');
    t_eq('grosse-stoerung-oeffnung', nb_slug('Große Störung Öffnung'), 'Grossbuchstaben mit Umlaut und sz');
    t_eq('', nb_slug('   '), 'leer bleibt leer');
    t_eq('ohne-rand', nb_slug('– ohne Rand –'), 'keine Bindestriche am Rand');
    t_eq(true, strlen(nb_slug(str_repeat('wort ', 30))) <= 60, 'hoechstens 60 Zeichen');
}

function test_eilmeldung_logbuch_und_verlauf()
{
    @unlink(NORDBAHN_EIL_LOG_FILE);
    $live = function ($title, $stand) {
        return [['id' => 'em-7', 'title' => $title, 'stand' => $stand, 'link' => 'https://www.nordbahn.de/x/em-7']];
    };
    nordbahn_update_eil_log($live('Weichenstörung in Pinneberg', '2026-10-17T05:15:00Z'), '2026-10-17T05:17:00Z');
    nordbahn_update_eil_log($live('Weichenstörung in Pinneberg', '2026-10-17T05:15:00Z'), '2026-10-17T05:19:00Z'); // nichts neu
    nordbahn_update_eil_log($live('Weichenstörung in Pinneberg behoben, noch Verspätungen', '2026-10-17T06:40:00Z'), '2026-10-17T06:41:00Z');
    $log = nordbahn_read_json(NORDBAHN_EIL_LOG_FILE);
    t_eq(2, count($log['em-7']['verlauf']), 'zwei Staende: neu und geaenderter Titel');
    t_eq(['2026-10-17T05:17:00Z', '2026-10-17T06:41:00Z', false], [$log['em-7']['first_live'], $log['em-7']['last_live'], isset($log['em-7']['weg'])], 'erst- und zuletzt gesehen, nicht aufgehoben');

    $data = function ($liveListe) {
        return [
            'received_at' => '2026-10-17T07:00:00Z', 'quellen' => ['nordbahn_ok' => true],
            'eilmeldungen' => [['id' => 'em-7', 'title' => 'Weichenstörung in Pinneberg', 'seen_at' => '2026-10-17T05:30:00Z', 'ki_titel' => 'RB61 hält nicht in Pinneberg', 'ki_text' => 'Weiche gestört.']],
            'eilmeldungen_live' => $liveListe,
            'eilmeldungen_log' => nordbahn_read_json(NORDBAHN_EIL_LOG_FILE),
        ];
    };
    $jetzt = strtotime('2026-10-17T07:00:00Z');
    $d = $data($live('Weichenstörung in Pinneberg behoben, noch Verspätungen', '2026-10-17T06:40:00Z'));
    $v = nb_eilmeldung_verlauf($d, nb_eilmeldung_finden($d, 'em-7'), $jetzt);
    t_eq(['aktiv', strtotime('2026-10-17T05:15:00Z'), null], [$v['status'], $v['start'], $v['ende']], 'aktiv, Beginn ist der erste Stand der Nordbahn');
    t_eq(['neu', 'text'], array_column($v['schritte'], 'art'), 'Gemeldet und Update');
    t_eq([strtotime('2026-10-17T06:40:00Z'), 'Nordbahn: „Weichenstörung in Pinneberg behoben, noch Verspätungen“'], [$v['schritte'][1]['ts'], $v['schritte'][1]['text']], 'Update mit Stand-Zeit und neuem Titel der Nordbahn');
    $html = nb_render_verlauf_schritte($v['schritte'], $jetzt, ['kopf_text' => 'Weiche gestört.', 'ohne_dauer' => true]);
    t_eq(false, strpos($html, 'Weiche gestört.'), 'Pendler-Text steht im Kopf, nicht noch im Verlauf');
    t_contains('<span class="zeitleiste__etikett">Update</span>', $html, 'Etikett Update');

    // Die Nordbahn nimmt die Meldung von der Seite: aufgehoben zum letzten Push, in dem sie stand
    nordbahn_update_eil_log([], '2026-10-17T07:11:00Z');
    nordbahn_update_eil_log([], '2026-10-17T07:13:00Z');
    $log = nordbahn_read_json(NORDBAHN_EIL_LOG_FILE);
    t_eq('2026-10-17T06:41:00Z', $log['em-7']['weg'], 'weg bleibt beim ersten Fehlen stehen');
    $d = $data([]);
    $v = nb_eilmeldung_verlauf($d, nb_eilmeldung_finden($d, 'em-7'), $jetzt + 3600);
    t_eq(['aufgehoben', strtotime('2026-10-17T06:41:00Z')], [$v['status'], $v['ende']], 'aufgehoben mit Zeit');
    t_eq(['neu', 'text', 'aufgehoben'], array_column($v['schritte'], 'art'), 'Verlauf endet mit Aufgehoben');

    // Steht sie wieder da, ist die Aufhebung zurueckgenommen
    nordbahn_update_eil_log($live('Weichenstörung in Pinneberg behoben, noch Verspätungen', '2026-10-17T06:40:00Z'), '2026-10-17T07:15:00Z');
    t_eq(false, isset(nordbahn_read_json(NORDBAHN_EIL_LOG_FILE)['em-7']['weg']), 'wieder live: nicht aufgehoben');

    // Meldung aus der Zeit vor dem Logbuch: nur Gemeldet, kein erfundenes Ende
    $d = $data([]);
    $d['eilmeldungen_log'] = [];
    $v = nb_eilmeldung_verlauf($d, nb_eilmeldung_finden($d, 'em-7'), $jetzt + 86400);
    t_eq(['alt', null, ['neu']], [$v['status'], $v['ende'], array_column($v['schritte'], 'art')], 'ohne Logbuch: nicht mehr akut, nur Gemeldet');
    @unlink(NORDBAHN_EIL_LOG_FILE);
}

function test_gleichzeitig_eine_liste()
{
    $st = [
        ['key' => 'A', 'titel' => 'Störung A', 'text' => 'x', 'start' => 300, 'ende' => 900, 'aktiv' => false],
        ['key' => 'B', 'titel' => 'Störung B', 'text' => 'x', 'start' => 100, 'ende' => 900, 'aktiv' => false],
    ];
    $eil = [
        ['id' => 'em-1', 'title' => 'Eil 1', 'text' => 'y', 'ts' => 200, 'stand' => null],
        ['id' => 'em-2', 'title' => 'Eil 2', 'text' => 'y', 'ts' => 50, 'stand' => null],
    ];
    $g = nb_gleichzeitig($st, $eil);
    t_eq(['Störung B', 'Eil 1', 'Störung A'], array_column($g, 'titel'), 'hoechstens drei, Stoerungen zuerst behalten, nach Zeit sortiert');
    t_eq(['', '', ''], array_column($g, 'text'), 'ohne Text');
    $html = nb_render_wichtig_eintrag($g[1], 1000);
    t_contains('feed__kicker--eilmeldung', $html, 'Rubrik Eilmeldung');
    t_eq([], nb_gleichzeitig([], []), 'leer');
}
