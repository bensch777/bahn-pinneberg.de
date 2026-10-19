<?php
/** Ereignisseiten: Verlauf im Logbuch, Finden, betroffene Zuege, Zeitraeume. */

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
    $log = nordbahn_read_json(NORDBAHN_LOG_FILE);
    $v = $log['X']['verlauf'];
    t_eq(4, count($v), 'vier Staende: neu, KI nachgetragen, Text, Ende');
    t_eq('2026-10-17T01:00:00Z', $v[0]['t'], 'erster Schritt traegt first_seen');
    t_eq('2026-10-17T04:30:00Z', $v[3]['t'], 'letzter Schritt zum Zeitpunkt der Aenderung');

    $schritte = nb_verlauf_schritte($v);
    t_eq(['neu', 'text', 'ende'], array_column($schritte, 'art'), 'nachgetragener Pendler-Text ist kein eigener Schritt');
    t_eq('Schwere Störung.', $schritte[0]['ki_text'], 'Pendler-Text im ersten Schritt eingearbeitet');
    $html = nb_render_verlauf_schritte($schritte, strtotime('2026-10-17 06:00:00 Europe/Berlin'));
    t_contains('Störung gemeldet', $html, 'Schritt neu');
    t_contains('Neuer Stand', $html, 'Schritt Text');
    t_contains('Neues voraussichtliches Ende', $html, 'Schritt Ende');
    t_contains('Gemeldetes Ende: heute, 12:00 Uhr', $html, 'Ende im Schritt');
    unlink(NORDBAHN_LOG_FILE);
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

    t_eq('./stoerung/BZI_1/', nb_link('stoerung', 'BZI_1'), 'relativer Link');
    nb_root('../../');
    t_eq('../../eilmeldung/em-1/', nb_link('eilmeldung', 'em-1'), 'relativer Link von einer Unterseite');
    nb_root('./');
    t_eq('https://bahn-pinneberg.de/stoerung/BZI_1/', nb_ereignis_url('stoerung', 'BZI_1'), 'absoluter Link');
}

function test_betroffene_zuege_im_zeitraum()
{
    if (!is_dir(NB_ZUGLOG_DIR)) {
        mkdir(NB_ZUGLOG_DIR, 0775, true);
    }
    $e = function ($zug, $plan, array $o = []) {
        return $o + ['zug' => $zug, 'linie' => 'RB61', 'ziel' => 'Hamburg Hbf', 'ab' => '', 'station' => 'APD', 'dir' => 'hin', 'plan' => $plan, 'versp' => 0, 'max' => 0, 'ausfall' => false, 'teil' => '', 'gleis' => false, 'n' => 3, 't' => 0];
    };
    nordbahn_write_json(NB_ZUGLOG_DIR . '/2026-10-17.json', [
        'APD|hin|1' => $e('1', '07:42', ['ausfall' => true]),
        'APD|hin|2' => $e('2', '08:12', ['versp' => 9, 'max' => 14]),
        'APD|hin|3' => $e('3', '08:40'),                                   // unauffaellig
        'APD|hin|4' => $e('4', '11:00', ['ausfall' => true]),              // ausserhalb
        'AH|rueck|5' => $e('5', '08:05', ['station' => 'AH', 'dir' => 'rueck', 'ziel' => 'Itzehoe', 'ab' => 'Hbf', 'teil' => 'beginnt']),
    ]);
    $start = strtotime('2026-10-17 07:30:00 Europe/Berlin');
    $ende = strtotime('2026-10-17 09:00:00 Europe/Berlin');
    $z = nb_zuege_im_zeitraum($start, $ende);
    t_eq(['1', '5', '2'], array_column($z['zuege'], 'zug'), 'nach Zeit sortiert, unauffaellige und spaetere fehlen');
    t_eq([3, 1, 1, 1], [$z['gesamt'], $z['ausfaelle'], $z['teil'], $z['spaet']], 'Zaehler');
    t_eq('Hamburg Hbf', $z['zuege'][1]['ab'], 'Hamburger Bahnhof als Klarname');
    $html = nb_render_zuege_liste($z);
    t_contains('<span class="zuege__zug">RB61</span> <span class="zuege__weg">ab Prisdorf nach Hamburg Hbf</span></td><td class="zuege__status"><span class="pill pill--ausfall">Ausgefallen</span>', $html, 'Ausfall');
    t_contains('<span class="pill pill--spaet">+9 min</span><small>zeitweise +14</small>', $html, 'Verspaetung mit Spitze');
    t_contains('Erst unterwegs gestartet', $html, 'Teilausfall');
    t_contains('Keine auffälligen Züge', nb_render_zuege_liste(nb_zuege_im_zeitraum($start + 10 * 86400, $ende + 10 * 86400)), 'leer');
    unlink(NB_ZUGLOG_DIR . '/2026-10-17.json');
}
