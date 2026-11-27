<?php
/**
 * Pendlermeldungen (api/pendler.php): Regeln, Pruefung der Anfrage, KI-Antworten,
 * Laufzeit, Bestaetigen, Ausblenden, Melden, Entscheidung des Betreibers, Sperre,
 * Kostenbremse und die oeffentliche Sicht.
 */

function test_pendler_regeln()
{
    t_eq(null, nb_pd_regeln('Durchsage: Personen im Gleis bei Tornesch, Weiterfahrt ca. 20 Min.'), 'normale Meldung');
    t_eq(null, nb_pd_regeln('RE7 um 7:42 ab Gleis 3, Zug 20123 hat Platz'), 'Uhrzeit und Zugnummer sind keine Telefonnummer');
    t_eq('zu_kurz', nb_pd_regeln('voll'), 'zu kurz');
    t_eq('zu_lang', nb_pd_regeln(str_repeat('a', 201)), 'zu lang');
    t_eq('link', nb_pd_regeln('Alles hier: https://example.org/info'), 'Link mit Schema');
    t_eq('link', nb_pd_regeln('Infos auf meinseite.de lesen'), 'Domain ohne Schema');
    t_eq('kontakt', nb_pd_regeln('Melde dich bei max@example.org bitte'), 'E-Mail-Adresse');
    t_eq('kontakt', nb_pd_regeln('Ruf an unter 0171 1234567 wegen Mitfahrt'), 'Telefonnummer');
    t_eq('kontrolle', nb_pd_regeln('Achtung, Kontrolleure im Zug ab Elmshorn'), 'Kontrolle');
    t_eq('kontrolle', nb_pd_regeln('Fahrkartenprüfung am Ausgang Pinneberg'), 'Fahrkartenpruefung');
    t_eq('beleidigung', nb_pd_regeln('Der Typ am Gleis ist ein Arschloch'), 'Beleidigung');
    t_eq(null, nb_pd_regeln('Zug nach Wrist steht seit 10 Minuten'), 'Wortteil "Wrist" ist keine Beleidigung');
    t_eq('Sooo voll!!! heute', nb_pd_bereinigen("  Sooooooo   voll!!!!!!!!\n heute "), 'Bereinigen: Leerraum und Wiederholungen');
}

function test_pendler_pruefen_schreiben()
{
    $site = 'https://test.local';
    $post = ['aktion' => 'schreiben', 'thema' => 'lage', 'bhf' => 'atm', 'zug' => '20123', 'text' => 'Zug steht vor Tornesch, Durchsage sagt 10 Min.', 'geheim' => str_repeat('ab', 16)];
    $p = nb_pd_pruefen_schreiben($post, t_feedback_server(), $site, ['20123' => true]);
    t_eq(200, $p['code'], 'gueltig');
    t_eq(['lage', 'ATM', '20123'], [$p['daten']['thema'], $p['daten']['bhf'], $p['daten']['zug']], 'Felder, Bahnhof in Grossbuchstaben');
    t_eq(null, nb_pd_pruefen_schreiben($post, t_feedback_server(), $site, [])['daten']['zug'], 'unbekannter Zug: Meldung ohne Zug');
    t_eq(200, nb_pd_pruefen_schreiben(['bhf' => 'AH'] + $post, t_feedback_server(), $site, [])['code'], 'Hamburg Hbf erlaubt');
    t_eq('STRECKE', nb_pd_pruefen_schreiben(['bhf' => 'strecke'] + $post, t_feedback_server(), $site, [])['daten']['bhf'], 'ohne Bahnhof erlaubt');
    t_eq('ungueltig', nb_pd_pruefen_schreiben(['thema' => 'chat'] + $post, t_feedback_server(), $site, [])['status'], 'unbekanntes Thema');
    t_eq('ungueltig', nb_pd_pruefen_schreiben(['bhf' => 'XYZ'] + $post, t_feedback_server(), $site, [])['status'], 'unbekannter Bahnhof');
    $abgelehnt = nb_pd_pruefen_schreiben(['text' => 'Kontrolle in der RB61!'] + $post, t_feedback_server(), $site, []);
    t_eq(['abgelehnt', 'kontrolle', 422], [$abgelehnt['status'], $abgelehnt['grund'], $abgelehnt['code']], 'Regel greift');
    t_eq('', nb_pd_pruefen_schreiben(['geheim' => 'kurz'] + $post, t_feedback_server(), $site, [])['daten']['geheim'], 'Geheimnis falsch geformt: leer');
    t_eq('bot', nb_pd_pruefen_schreiben($post, t_feedback_server(['HTTP_USER_AGENT' => 'python-requests']), $site, [])['status'], 'Bot-Filter');
    t_eq('honeypot', nb_pd_pruefen_schreiben(['website' => 'x'] + $post, t_feedback_server(), $site, [])['status'], 'Honeypot');
}

function test_pendler_ki_antworten()
{
    // Ohne eingerichtete KI-Pruefung: sofort sichtbar. Eingerichtet, aber ohne Urteil: wartet.
    t_eq('frei', nb_pd_start_status('nur_regeln', null), 'ohne KI sofort sichtbar');
    t_eq('wartet', nb_pd_start_status('ki', null), 'KI ohne Urteil wartet');
    t_eq('wartet', nb_pd_start_status('ohne_ki', null), 'Kostenbremse wartet');
    t_eq('frei', nb_pd_start_status('ki', ['ok' => true]), 'KI ok');

    $m = ['thema' => 'sev', 'bhf' => 'AP', 'zug' => null, 'text' => 'SEV ab ZOB Steig 4'];
    $gesendet = null;
    $fetch = function ($antwort) use (&$gesendet) {
        return function ($url, $json, $token) use ($antwort, &$gesendet) {
            $gesendet = [$url, json_decode($json, true), $token];

            return $antwort;
        };
    };
    t_eq(['ok' => true], nb_pd_ki_pruefen($m, 'https://n8n.test/webhook/x', 'tok', $fetch('{"ok":true}')), 'zulaessig');
    t_eq(['https://n8n.test/webhook/x', 'Ersatzverkehr', 'Pinneberg', 'tok'], [$gesendet[0], $gesendet[1]['thema'], $gesendet[1]['bahnhof'], $gesendet[2]], 'Anfrage mit Klartext');
    t_eq(['ok' => false, 'grund' => 'person'], nb_pd_ki_pruefen($m, 'https://n8n.test/x', 'tok', $fetch('{"ok":false,"grund":"person"}')), 'abgelehnt mit Grund');
    t_eq(['ok' => false, 'grund' => 'sonst'], nb_pd_ki_pruefen($m, 'https://n8n.test/x', 'tok', $fetch('{"ok":false,"grund":"<b>"}')), 'unbekannter Grund wird sonst');
    t_eq(null, nb_pd_ki_pruefen($m, 'https://n8n.test/x', 'tok', $fetch(null)), 'nicht erreichbar');
    t_eq(null, nb_pd_ki_pruefen($m, 'https://n8n.test/x', 'tok', $fetch('kaputt')), 'keine JSON-Antwort');
    t_eq(null, nb_pd_ki_pruefen($m, 'https://n8n.test/x', 'tok', $fetch('{"ok":"ja"}')), 'ok nicht boolesch: unklar, also von Hand');
    t_eq(null, nb_pd_ki_pruefen($m, '', 'tok', $fetch('{"ok":true}')), 'ohne Webhook keine Pruefung');
}

function t_pd_neu($thema = 'lage', $text = 'Zug steht vor Tornesch seit 5 Minuten')
{
    return ['thema' => $thema, 'bhf' => 'ATM', 'zug' => '20123', 'text' => $text, 'geheim' => str_repeat('cd', 16)];
}

function test_pendler_laufzeit_bestaetigen_ausblenden()
{
    $now = nb_ts(T_NOW);
    list($d, $id) = nb_pd_anlegen([], t_pd_neu(), 'k1', 'ip1', 'frei', $now);
    $o = nb_pd_oeffentlich($d, $now + 60);
    t_eq(1, count($o), 'sichtbar');
    t_eq(['lage', 'Tornesch', '20123', 0], [$o[0]['thema'], $o[0]['bhf_name'], $o[0]['zug'], $o[0]['stimmt']], 'oeffentliche Felder');
    t_assert(!isset($o[0]['kennung']) && !isset($o[0]['geheim']) && !isset($o[0]['ip']), 'keine Kennungen nach aussen');
    t_eq([], nb_pd_oeffentlich($d, $now + 3601), 'Lage unterwegs nach einer Stunde weg');

    // Stimmt noch: verlaengert um die Laufzeit ab jetzt, eigene Bestaetigung zaehlt nicht
    list($d, $s) = nb_pd_aktion($d, $id, 'stimmt', 'k1', 'ip1', '', $now + 1800);
    t_eq(['bestaetigt', 0], [$s, nb_pd_oeffentlich($d, $now + 1800)[0]['stimmt']], 'eigene Bestaetigung zaehlt nicht');
    list($d) = nb_pd_aktion($d, $id, 'stimmt', 'k2', 'ip2', '', $now + 1800);
    list($d) = nb_pd_aktion($d, $id, 'stimmt', 'k2', 'ip2', '', $now + 1900);
    t_eq(1, nb_pd_oeffentlich($d, $now + 1900)[0]['stimmt'], 'je Besucher einmal');
    t_eq(1, count(nb_pd_oeffentlich($d, $now + 5000)), 'nach Bestaetigung laeuft sie laenger');
    // nie laenger als 24 Stunden nach dem Schreiben
    for ($t = $now + 3000; $t < $now + 30 * 3600; $t += 3000) {
        list($d) = nb_pd_aktion($d, $id, 'stimmt', 'k' . $t, 'ip' . $t, '', $t);
    }
    t_eq([], nb_pd_oeffentlich($d, $now + NB_PD_MAX_LAUFZEIT + 1), 'hoechstens 24 Stunden');

    // Stimmt nicht mehr: zwei verschiedene IPs beenden
    list($d, $id) = nb_pd_anlegen([], t_pd_neu(), 'k1', 'ip1', 'frei', $now);
    list($d, $s) = nb_pd_aktion($d, $id, 'stimmt_nicht', 'k2', 'ip2', '', $now + 60);
    t_eq('notiert', $s, 'erste Gegenmeldung notiert');
    list($d, $s) = nb_pd_aktion($d, $id, 'stimmt_nicht', 'k3', 'ip2', '', $now + 70);
    t_eq('notiert', $s, 'gleiche IP mit anderem Browser zaehlt nicht doppelt');
    list($d, $s) = nb_pd_aktion($d, $id, 'stimmt_nicht', 'k4', 'ip3', '', $now + 80);
    t_eq(['beendet', []], [$s, nb_pd_oeffentlich($d, $now + 90)], 'zweite IP beendet');
    t_eq('vorbei', nb_pd_aktion($d, $id, 'stimmt', 'k5', 'ip5', '', $now + 100)[1], 'abgelaufene laesst sich nicht bestaetigen');
}

function test_pendler_melden_entscheiden_loeschen()
{
    $now = nb_ts(T_NOW);
    list($d, $id) = nb_pd_anlegen([], t_pd_neu('bahnhof', 'Aufzug Gleis 2 ist kaputt seit heute'), 'k1', 'ip1', 'frei', $now);
    list($d) = nb_pd_aktion($d, $id, 'unangemessen', 'k2', 'ip2', '', $now + 10);
    list($d, $s) = nb_pd_aktion($d, $id, 'unangemessen', 'k3', 'ip3', '', $now + 20);
    t_eq(['gemeldet', []], [$s, nb_pd_oeffentlich($d, $now + 30)], 'zweimal unangemessen: ausgeblendet');
    list($d2, $s) = nb_pd_entscheiden($d, $id, 'freigeben', $now + 40);
    t_eq(['freigegeben', 1], [$s, count(nb_pd_oeffentlich($d2, $now + 50))], 'Betreiber gibt wieder frei');
    list($d3, $s) = nb_pd_entscheiden($d, $id, 'entfernen', $now + 40);
    t_eq(['entfernt', []], [$s, nb_pd_oeffentlich($d3, $now + 50)], 'Betreiber entfernt');
    t_eq(1, $d3['sperre']['k1']['anzahl'], 'Entfernen zaehlt gegen den Verfasser');
    t_eq(1, nb_pd_entscheiden($d3, $id, 'entfernen', $now + 60)[0]['sperre']['k1']['anzahl'], 'zweimal entfernen zaehlt einmal');

    // Wartende Meldung: Freigabe gibt die volle Laufzeit ab jetzt
    list($d, $id) = nb_pd_anlegen([], t_pd_neu(), 'k1', 'ip1', 'wartet', $now);
    t_eq([], nb_pd_oeffentlich($d, $now + 10), 'wartet: nicht sichtbar');
    list($d) = nb_pd_entscheiden($d, $id, 'freigeben', $now + 1800);
    t_eq(1, count(nb_pd_oeffentlich($d, $now + 1800 + 3500)), 'nach Freigabe eine volle Stunde');

    // Loeschen nur mit dem Geheimnis des Verfassers
    t_eq('verboten', nb_pd_aktion($d, $id, 'loeschen', 'k9', 'ip9', str_repeat('00', 16), $now)[1], 'falsches Geheimnis');
    list($d, $s) = nb_pd_aktion($d, $id, 'loeschen', 'k9', 'ip9', str_repeat('CD', 16), $now + 1900);
    t_eq(['geloescht', []], [$s, nb_pd_oeffentlich($d, $now + 1910)], 'richtiges Geheimnis loescht, auch von anderem Geraet');
    list($d, $id) = nb_pd_anlegen([], ['geheim' => ''] + t_pd_neu(), 'k1', 'ip1', 'frei', $now);
    t_eq('verboten', nb_pd_aktion($d, $id, 'loeschen', 'k1', 'ip1', '', $now)[1], 'ohne Geheimnis kein Loeschen');
    t_eq('unbekannt', nb_pd_aktion($d, 'ffffffffffff', 'stimmt', 'k1', 'ip1', '', $now)[1], 'unbekannte ID');
}

function test_pendler_sperre_doppelt_kostenbremse()
{
    $now = nb_ts(T_NOW);
    $d = [];
    for ($i = 0; $i < NB_PD_SPERRE - 1; $i++) {
        $d = nb_pd_verwarnen($d, 'k1', $now);
    }
    t_assert(!nb_pd_gesperrt($d, 'k1', $now), 'unter der Grenze frei');
    $d = nb_pd_verwarnen($d, 'k1', $now);
    t_assert(nb_pd_gesperrt($d, 'k1', $now), 'an der Grenze gesperrt');
    t_assert(!nb_pd_gesperrt($d, 'k2', $now), 'andere Kennung frei');
    t_assert(!nb_pd_gesperrt($d, 'k1', $now + 86400), 'am naechsten Tag frei');
    t_eq([], nb_pd_aufraeumen($d, $now + 86400)['sperre'], 'Sperren von gestern werden aufgeraeumt');

    list($d, $id) = nb_pd_anlegen([], t_pd_neu('sev', 'SEV-Bus hält am ZOB, Steig 4'), 'k1', 'ip1', 'frei', $now);
    t_assert(nb_pd_doppelt($d, 'sev bus haelt am ZOB steig 4', $now + 60) === false, 'anderer Wortlaut (ae) ist kein Doppel');
    t_assert(nb_pd_doppelt($d, 'SEV Bus hält am ZOB Steig 4!', $now + 60), 'gleicher Text mit anderer Zeichensetzung ist doppelt');
    t_assert(!nb_pd_doppelt($d, 'SEV Bus hält am ZOB Steig 4!', $now + NB_PD_DOPPELT + 1), 'nach zwei Stunden wieder erlaubt');
    t_eq(0, count(nb_pd_aufraeumen($d, $now + NB_PD_AUFBEWAHREN + 1)['meldungen']), 'nach sieben Tagen ist der Text weg');

    $d = [];
    for ($i = 0; $i < NB_PD_KI_MAX; $i++) {
        list($d, $ok) = nb_pd_ki_erlaubt($d, $now + $i);
        t_assert($ok, 'Pruefung ' . $i . ' erlaubt');
    }
    list($d, $ok) = nb_pd_ki_erlaubt($d, $now + 100);
    t_assert(!$ok, 'Kostenbremse greift');
    list($d, $ok) = nb_pd_ki_erlaubt($d, $now + 3601);
    t_assert($ok, 'nach einer Stunde wieder frei');
}

function test_pendler_verwaltung_und_discord()
{
    t_eq('', nb_pd_signatur('abc123', ''), 'ohne Token keine Verwaltung');
    $sig = nb_pd_signatur('abc123', 'geheim');
    t_eq(32, strlen($sig), 'Signatur gekuerzt');
    t_assert($sig !== nb_pd_signatur('abc124', 'geheim'), 'Signatur haengt an der ID');
    t_eq('https://x.test/pendler/verwalten/?id=abc123&sig=' . $sig, nb_pd_verwalten_url('abc123', 'https://x.test', 'geheim'), 'Verwaltungslink');

    $now = nb_ts(T_NOW);
    list($d, $id) = nb_pd_anlegen([], t_pd_neu('lage', '@everyone Zug steht vor Tornesch'), 'k1', 'ip1', 'wartet', $now);
    $n = nb_pd_discord_nachricht($d['meldungen'][0], 'wartet', 'https://x.test', 'geheim');
    t_eq(['parse' => []], $n['allowed_mentions'], 'keine Erwaehnungen');
    t_eq('@everyone Zug steht vor Tornesch', $n['embeds'][0]['description'], 'Text unveraendert');
    t_assert(strpos(json_encode($n), 'pendler\/verwalten') !== false, 'Link zur Verwaltung');
    t_eq(['Lage unterwegs', 'Tornesch', '20123'], array_column($n['embeds'][0]['fields'], 'value') === [] ? [] : array_slice(array_column($n['embeds'][0]['fields'], 'value'), 0, 3), 'Thema, Bahnhof, Zug');
}

function test_pendler_in_lage_md()
{
    $now = nb_ts(T_NOW);
    @unlink(nb_pd_datei());
    t_eq('', nb_lage_md_pendler($now), 'ohne Meldungen kein Abschnitt');
    list($d) = nb_pd_anlegen([], t_pd_neu('sev', 'SEV-Bus hält am ZOB, Steig 4'), 'k1', 'ip1', 'frei', $now - 600);
    list($d) = nb_pd_anlegen($d, t_pd_neu('lage', 'Wartet noch auf Freigabe hier'), 'k2', 'ip2', 'wartet', $now - 300);
    list($d) = nb_pd_anlegen($d, ['bhf' => 'STRECKE'] + t_pd_neu('lage', 'Durchsage: alle Züge 10 Min. später'), 'k3', 'ip3', 'frei', $now - 200);
    @mkdir(nb_pd_dir(), 0775, true);
    file_put_contents(nb_pd_datei(), json_encode($d));
    $md = nb_lage_md_pendler($now);
    t_assert(strpos($md, '## Von Pendlern (ungeprüft)') === 0, 'eigener, gekennzeichneter Abschnitt');
    t_assert(strpos($md, 'Ersatzverkehr, Tornesch: SEV-Bus hält am ZOB, Steig 4') !== false, 'freigegebene Meldung steht drin');
    t_assert(strpos($md, 'Wartet noch') === false, 'wartende Meldung nicht');
    t_assert(strpos($md, 'Lage unterwegs: Durchsage: alle Züge') !== false, 'ohne Bahnhof ohne Ortsangabe');
    @unlink(nb_pd_datei());
}
