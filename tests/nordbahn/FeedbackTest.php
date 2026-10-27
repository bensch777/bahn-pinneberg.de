<?php
/**
 * Feedback-Endpunkt (api/feedback.php): Pruefung der Eingaben, Stundenlimit,
 * Discord-Nachricht und das Markup des Dialogs.
 */

function t_feedback_server(array $extra = [])
{
    return $extra + [
        'HTTP_HOST'       => 'test.local',
        'HTTP_ORIGIN'     => 'https://test.local',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1',
        'REMOTE_ADDR'     => '203.0.113.7',
    ];
}

function test_feedback_pruefung()
{
    $site = 'https://test.local';
    $post = ['nachricht' => "Die RB61 um 7:42 fehlt\x07 in der Liste.", 'art' => 'fehler', 'kontakt' => ' ben@example.org ', 'seite' => '/statistik/'];
    $e = nb_feedback_pruefen($post, t_feedback_server(), $site);
    t_eq(200, $e['code'], 'gueltige Nachricht');
    t_eq('ok', $e['status'], 'Status ok');
    t_eq('Die RB61 um 7:42 fehlt in der Liste.', $e['daten']['nachricht'], 'Steuerzeichen raus');
    t_eq('fehler', $e['daten']['art'], 'Art uebernommen');
    t_eq('ben@example.org', $e['daten']['kontakt'], 'Kontakt getrimmt');
    t_eq('/statistik/', $e['daten']['seite'], 'Pfad uebernommen');
    t_assert(strpos($e['daten']['browser'], 'iPhone') !== false, 'Browser-Kennung dabei');

    t_eq('sonst', nb_feedback_pruefen(['art' => 'hack'] + $post, t_feedback_server(), $site)['daten']['art'], 'unbekannte Art wird sonst');
    t_eq('', nb_feedback_pruefen(['seite' => 'https://boese.example/'] + $post, t_feedback_server(), $site)['daten']['seite'], 'fremde Adresse als Seite faellt weg');
    t_eq('zu_kurz', nb_feedback_pruefen(['nachricht' => 'Hi'] + $post, t_feedback_server(), $site)['status'], 'zu kurz');
    t_eq(400, nb_feedback_pruefen(['nachricht' => ''] + $post, t_feedback_server(), $site)['code'], 'leer ist 400');
    t_eq('honeypot', nb_feedback_pruefen(['website' => 'http://spam'] + $post, t_feedback_server(), $site)['status'], 'Honeypot erkannt');
    t_eq('bot', nb_feedback_pruefen($post, t_feedback_server(['HTTP_USER_AGENT' => 'python-requests/2.31']), $site)['status'], 'Bot-UA');
    t_eq('bot', nb_feedback_pruefen($post, t_feedback_server(['HTTP_USER_AGENT' => '']), $site)['status'], 'ohne UA');
    t_eq('origin', nb_feedback_pruefen($post, t_feedback_server(['HTTP_ORIGIN' => 'https://anderswo.example']), $site)['status'], 'fremder Origin');
    t_eq(200, nb_feedback_pruefen($post, t_feedback_server(['HTTP_ORIGIN' => 'https://bahn-pinneberg.de']), 'https://bahn-pinneberg.de')['code'], 'Origin gleich Site-URL');
    $server = t_feedback_server();
    unset($server['HTTP_ORIGIN']);
    t_eq(200, nb_feedback_pruefen($post, $server, $site)['code'], 'ohne Origin-Header erlaubt');

    $lang = str_repeat('a', 2500);
    t_eq(2000, mb_strlen(nb_feedback_pruefen(['nachricht' => $lang] + $post, t_feedback_server(), $site)['daten']['nachricht']), 'auf 2000 Zeichen gekuerzt');
}

function test_feedback_kennung_und_limit()
{
    $now = nb_ts(T_NOW);
    $a = nb_feedback_kennung(t_feedback_server(), $now);
    t_eq(16, strlen($a), 'Kennung 16 Zeichen');
    t_eq($a, nb_feedback_kennung(t_feedback_server(), $now + 600), 'gleicher Tag, gleiche Kennung');
    t_assert($a !== nb_feedback_kennung(t_feedback_server(), $now + 86400), 'naechster Tag, andere Kennung');
    t_assert($a !== nb_feedback_kennung(t_feedback_server(['REMOTE_ADDR' => '203.0.113.8']), $now), 'andere IP, andere Kennung');
    t_eq($a, nb_feedback_kennung(t_feedback_server(['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1', 'REMOTE_ADDR' => '10.0.0.1']), $now), 'X-Forwarded-For zaehlt');

    $dir = NORDBAHN_CACHE_DIR . '/feedback-test-' . getmypid();
    for ($i = 0; $i < 5; $i++) {
        t_eq(true, nb_feedback_limit($a, $dir, $now + $i), 'Nachricht ' . ($i + 1) . ' erlaubt');
    }
    t_eq(false, nb_feedback_limit($a, $dir, $now + 10), 'sechste innerhalb der Stunde gesperrt');
    t_eq(true, nb_feedback_limit('b' . substr($a, 1), $dir, $now + 10), 'anderer Besucher unberuehrt');
    t_eq(true, nb_feedback_limit($a, $dir, $now + 3601), 'nach einer Stunde wieder frei');
    t_assert(is_file($dir . '/' . $a . '.json'), 'Stempel liegen als Datei');
    foreach (glob($dir . '/*.json') as $f) {
        unlink($f);
    }
    rmdir($dir);
}

function test_feedback_nachricht_und_markup()
{
    $now = nb_ts(T_NOW);
    $d = ['nachricht' => 'Hallo @everyone', 'art' => 'idee', 'kontakt' => '@ben', 'seite' => '/karte/', 'browser' => 'Safari'];
    $p = nb_feedback_nachricht($d, 'https://bahn-pinneberg.de', $now);
    t_eq([], $p['allowed_mentions']['parse'], 'keine Erwaehnungen erlaubt');
    t_eq('Idee', $p['embeds'][0]['title'], 'Titel nach Art');
    t_eq('Hallo @everyone', $p['embeds'][0]['description'], 'Text unveraendert');
    t_eq(['Seite', 'Kontakt', 'Browser'], array_column($p['embeds'][0]['fields'], 'name'), 'Felder');
    t_eq('[/karte/](https://bahn-pinneberg.de/karte/)', $p['embeds'][0]['fields'][0]['value'], 'Seite als Link');
    t_eq(['Browser'], array_column(nb_feedback_nachricht(['kontakt' => '', 'seite' => ''] + $d, 'https://x', $now)['embeds'][0]['fields'], 'name'), 'ohne Seite und Kontakt nur Browser');
    t_eq('Fehler gemeldet', nb_feedback_nachricht(['art' => 'fehler'] + $d, 'https://x', $now)['embeds'][0]['title'], 'Fehler');

    $html = nb_render_feedback('../');
    t_contains('<button type="button" class="feedback" id="feedback-open" aria-haspopup="dialog" aria-controls="feedback-dialog" hidden>', $html, 'Knopf bis zum Script versteckt');
    t_contains('<dialog class="feedback-dialog" id="feedback-dialog" aria-labelledby="feedback-titel">', $html, 'Dialog');
    t_contains('<form class="feedback-form" id="feedback-form" method="post" action="../api/feedback" novalidate>', $html, 'Formular zum Endpunkt');
    t_contains('name="website" tabindex="-1"', $html, 'Honeypot');
    t_contains('<option value="sonst" selected>Sonstiges</option>', $html, 'Sonstiges vorausgewaehlt');
    t_contains('<script src="../feedback.js?v=', $html, 'Script dabei');
    t_eq('', nordbahn_feedback_webhook(), 'ohne config.php kein Webhook');
    t_eq(false, strpos(t_seite_rendern('faq/index.php'), 'feedback-open'), 'ohne Webhook kein Knopf im Footer');
}
