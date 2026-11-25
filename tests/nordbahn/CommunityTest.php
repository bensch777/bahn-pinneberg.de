<?php
/**
 * Fahrgastmeldungen (api/community.php): Pruefung, Schwelle, Ablauf, Ausblenden,
 * Zuruecknehmen und welche Zuege gerade gemeldet werden duerfen.
 */

function test_community_pruefung()
{
    $site = 'https://test.local';
    $bekannt = ['20123' => true];
    $post = ['zug' => '20123', 'art' => 'steht', 'aktion' => 'melden'];
    $p = nb_cm_pruefen($post, t_feedback_server(), $site, $bekannt);
    t_eq(200, $p['code'], 'gueltige Meldung');
    t_eq(['20123', 'steht', 'melden'], [$p['zug'], $p['art'], $p['aktion']], 'Felder uebernommen');
    t_eq('ungueltig', nb_cm_pruefen(['art' => 'freitext'] + $post, t_feedback_server(), $site, $bekannt)['status'], 'unbekannte Art');
    t_eq('ungueltig', nb_cm_pruefen(['aktion' => 'loeschen'] + $post, t_feedback_server(), $site, $bekannt)['status'], 'unbekannte Aktion');
    t_eq('ungueltig', nb_cm_pruefen(['zug' => 'abc'] + $post, t_feedback_server(), $site, $bekannt)['status'], 'Zugnummer ohne Ziffern');
    t_eq('unbekannter_zug', nb_cm_pruefen(['zug' => '99999'] + $post, t_feedback_server(), $site, $bekannt)['status'], 'Zug faehrt gerade nicht');
    t_eq(200, nb_cm_pruefen(['zug' => '99999', 'aktion' => 'zurueck'] + $post, t_feedback_server(), $site, $bekannt)['code'], 'Zuruecknehmen geht immer');
    t_eq('bot', nb_cm_pruefen($post, t_feedback_server(['HTTP_USER_AGENT' => 'curl/8.0']), $site, $bekannt)['status'], 'Bot-Filter');
    t_eq('origin', nb_cm_pruefen($post, t_feedback_server(['HTTP_ORIGIN' => 'https://boese.example']), $site, $bekannt)['status'], 'fremder Origin');
    t_eq('honeypot', nb_cm_pruefen(['website' => 'x'] + $post, t_feedback_server(), $site, $bekannt)['status'], 'Honeypot');
}

function test_community_schwelle_ablauf_und_ausblenden()
{
    $now = nb_ts(T_NOW);
    $d = [];
    list($d, $s) = nb_cm_anwenden($d, '20123', 'voll', 'melden', 'aaaa', $now);
    t_eq('gemeldet', $s, 'Status gemeldet');
    list($d) = nb_cm_anwenden($d, '20123', 'voll', 'melden', 'aaaa', $now + 60);
    t_eq(1, count($d['20123']['voll']), 'derselbe Besucher zaehlt einmal');
    $a = nb_cm_aktiv($d, $now + 120, 1);
    t_eq('voll', $a['20123'][0]['art'], 'Schwelle 1: sofort sichtbar');
    t_eq(1, $a['20123'][0]['anzahl'], 'ein Fahrgast');
    t_eq([], nb_cm_aktiv($d, $now + 120, 2), 'Schwelle 2: eine Meldung reicht nicht');
    list($d) = nb_cm_anwenden($d, '20123', 'voll', 'melden', 'bbbb', $now + 180);
    t_eq(2, nb_cm_aktiv($d, $now + 200, 2)['20123'][0]['anzahl'], 'Schwelle 2: zwei Besucher');

    // Ablauf: "voll" 30 Minuten nach der letzten Meldung, "steht" nach 15
    t_eq(1, nb_cm_aktiv($d, $now + 60 + 30 * 60, 1)['20123'][0]['anzahl'], 'nach 30 Min. zaehlt nur noch die juengere');
    t_eq([], nb_cm_aktiv($d, $now + 180 + 30 * 60 + 1, 1), 'nach 30 Min. ohne neue Meldung weg');
    list($d) = nb_cm_anwenden($d, '20999', 'steht', 'melden', 'aaaa', $now);
    t_assert(isset(nb_cm_aktiv($d, $now + 14 * 60, 1)['20999']), 'steht nach 14 Min. noch da');
    t_assert(!isset(nb_cm_aktiv($d, $now + 16 * 60, 1)['20999']), 'steht nach 16 Min. weg');

    // Reihenfolge der Arten: steht vor voll
    list($d) = nb_cm_anwenden($d, '20123', 'steht', 'melden', 'cccc', $now + 200);
    t_eq(['steht', 'voll'], array_column(nb_cm_aktiv($d, $now + 220, 1)['20123'], 'art'), 'steht zuerst');

    // Zuruecknehmen nimmt nur die eigene Meldung
    list($d2, $s) = nb_cm_anwenden($d, '20123', 'voll', 'zurueck', 'aaaa', $now + 240);
    t_eq('zurueck', $s, 'Status zurueck');
    t_eq(['bbbb'], array_keys($d2['20123']['voll']), 'andere Meldung bleibt');

    // Stimmt nicht mehr verwirft alles dieser Art, neue Meldungen danach zaehlen wieder
    list($d3, $s) = nb_cm_anwenden($d, '20123', 'voll', 'stimmt_nicht', 'dddd', $now + 240);
    t_eq('ausgeblendet', $s, 'Status ausgeblendet');
    t_eq(['steht'], array_column(nb_cm_aktiv($d3, $now + 260, 1)['20123'], 'art'), 'voll weg, steht bleibt');
    list($d3) = nb_cm_anwenden($d3, '20123', 'voll', 'melden', 'eeee', $now + 300);
    t_eq(['steht', 'voll'], array_column(nb_cm_aktiv($d3, $now + 320, 1)['20123'], 'art'), 'neue Meldung zaehlt wieder');
    list($d4) = nb_cm_anwenden(['1' => ['kurz' => ['x' => $now]]], '1', 'kurz', 'stimmt_nicht', 'y', $now);
    t_eq([], $d4, 'leere Zuege werden aufgeraeumt');
}

function test_community_bekannte_zuege()
{
    $now = nb_ts(T_NOW);
    $dir = NORDBAHN_CACHE_DIR . '/community-zuege-' . getmypid();
    @mkdir($dir, 0775, true);
    $iso = function ($s) use ($now) { return (new DateTime('@' . ($now + $s)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('c'); };
    nordbahn_write_json($dir . '/karte.json', ['ok' => true, 'fetched_at' => $iso(-60), 'zuege' => [['zug' => '81234'], ['zug' => '']]]);
    nordbahn_write_json($dir . '/abfahrten-APD.json', ['ok' => true, 'fetched_at' => $iso(-30), 'richtungen' => [
        'hin'   => [['zug' => '20001', 'zeit' => $iso(5 * 60), 'ist' => null], ['zug' => '20003', 'zeit' => $iso(50 * 60)]],
        'rueck' => [['zug' => '20002', 'zeit' => $iso(10 * 60), 'ist' => $iso(40 * 60)], ['zug' => '20004', 'zeit' => $iso(60), 'ausfall' => true]],
    ]]);
    nordbahn_write_json($dir . '/abfahrten-AEL.json', ['ok' => true, 'fetched_at' => $iso(-3600), 'richtungen' => ['hin' => [['zug' => '20005', 'zeit' => $iso(60)]]]]);
    $z = nb_cm_bekannte_zuege($now, $dir);
    t_eq(['20001', '20002', '81234'], (function ($a) { $k = array_map('strval', array_keys($a)); sort($k); return $k; })($z), 'Karte, Tafel laut Plan bald ab (auch verspaetet); nicht: spaet, Ausfall, alte Tafel');
    foreach (glob($dir . '/*.json') as $f) {
        unlink($f);
    }
    rmdir($dir);
}
