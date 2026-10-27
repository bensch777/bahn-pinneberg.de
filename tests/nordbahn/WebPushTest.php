<?php
/** Web Push: Verschluesselung, VAPID und Ereignis-Ableitung. */

require_once __DIR__ . '/../../api/webpush.php';
require_once __DIR__ . '/../../api/notify.php';

function test_webpush_verschluesselung_roundtrip()
{
    // Der "Browser": eigenes Schluesselpaar und auth-Geheimnis
    $browser = wp_new_keypair();
    $uaPub = wp_raw_public_key($browser);
    $auth = random_bytes(16);
    $payload = json_encode(['title' => 'Zugausfall RB 71 um 07:18 Uhr', 'body' => 'Prisdorf → Hamburg. Defektes Stellwerk']);

    $body = wp_encrypt(wp_b64url_encode($uaPub), wp_b64url_encode($auth), $payload);
    t_assert($body !== null, 'Verschluesselung liefert Daten');

    // Header auseinandernehmen (RFC 8188): salt(16) rs(4) idlen(1) keyid(65)
    $salt = substr($body, 0, 16);
    $rs = unpack('N', substr($body, 16, 4))[1];
    $idlen = ord($body[20]);
    $asPub = substr($body, 21, 65);
    $record = substr($body, 86);
    t_eq(4096, $rs, 'Record-Groesse');
    t_eq(65, $idlen, 'Server-Schluessel 65 Bytes');

    // Entschluesseln wie der Browser (RFC 8291)
    $shared = openssl_pkey_derive(wp_public_key_from_raw($asPub), $browser, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $klar = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
    t_eq($payload . "\x02", $klar, 'Klartext mit Trennzeichen 0x02');

    t_eq(null, wp_encrypt('kaputt', wp_b64url_encode($auth), 'x'), 'kaputter Browser-Schluessel');
    t_eq(null, wp_encrypt(wp_b64url_encode($uaPub), 'kurz', 'x'), 'kaputtes auth');
    t_eq(null, wp_encrypt(wp_b64url_encode($uaPub), wp_b64url_encode($auth), str_repeat('x', 4000)), 'zu lange Nachricht');
}

function test_webpush_vapid_jwt()
{
    $key = wp_new_keypair();
    openssl_pkey_export($key, $pem);
    $exp = time() + 3600;
    $jwt = wp_vapid_jwt('https://fcm.googleapis.com', 'mailto:hallo@bahn-pinneberg.de', $pem, $exp);
    t_assert(is_string($jwt), 'JWT erzeugt');
    [$h, $c, $s] = explode('.', $jwt);
    t_eq(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(wp_b64url_decode($h), true), 'Header');
    t_eq(['aud' => 'https://fcm.googleapis.com', 'exp' => $exp, 'sub' => 'mailto:hallo@bahn-pinneberg.de'], json_decode(wp_b64url_decode($c), true), 'Claims');
    $raw = wp_b64url_decode($s);
    t_eq(64, strlen($raw), 'Signatur r||s');
    $pub = openssl_pkey_get_public(openssl_pkey_get_details($key)['key']);
    t_eq(1, openssl_verify($h . '.' . $c, wp_raw_sig_to_der($raw), $pub, OPENSSL_ALGO_SHA256), 'Signatur prueft mit dem oeffentlichen Schluessel');
    t_eq(wp_b64url_encode(wp_raw_public_key($key)), wp_vapid_public_from_private($pem), 'oeffentlicher Schluessel aus dem privaten');
    t_eq(null, wp_vapid_jwt('https://x', 'mailto:x', 'kein pem', $exp), 'ohne Schluessel null');
}

function test_push_abo_bereinigung()
{
    $key = wp_new_keypair();
    $gut = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => wp_b64url_encode(wp_raw_public_key($key)), 'auth' => wp_b64url_encode(random_bytes(16))]];
    $sub = nb_push_clean_subscription($gut);
    t_eq($gut['endpoint'], $sub['endpoint'], 'gueltiges Abo');
    t_eq(null, nb_push_clean_subscription(['endpoint' => 'http://unsicher', 'keys' => $gut['keys']]), 'nur https');
    t_eq(null, nb_push_clean_subscription(['endpoint' => $gut['endpoint'], 'keys' => ['p256dh' => 'x', 'auth' => 'y']]), 'kaputte Schluessel');
    t_eq(null, nb_push_clean_subscription('nix'), 'kein Array');
}

function test_push_pendelzeit()
{
    $tz = new DateTimeZone('Europe/Berlin');
    t_eq(true, nb_push_in_pendelzeit((new DateTime('2026-10-15 07:30', $tz))->getTimestamp()), 'Donnerstag frueh');
    t_eq(true, nb_push_in_pendelzeit((new DateTime('2026-10-15 18:59', $tz))->getTimestamp()), 'Donnerstag abends');
    t_eq(false, nb_push_in_pendelzeit((new DateTime('2026-10-15 12:00', $tz))->getTimestamp()), 'mittags nicht');
    t_eq(false, nb_push_in_pendelzeit((new DateTime('2026-10-17 07:30', $tz))->getTimestamp()), 'Samstag nicht');
}

function test_push_ereignisse_aus_daten()
{
    $now = 1000000;
    $state = [];
    $alt = ['stoerungen' => [['key' => 'A', 'titel' => 'Alt', 'ki_titel' => 'Alte Störung']], 'eilmeldungen' => [['id' => 'em-1', 'title' => 'x']]];
    $neu = ['stoerungen' => [['key' => 'A', 'titel' => 'Alt'], ['key' => 'B', 'titel' => 'Signalstörung', 'text' => 'Technik']],
            'eilmeldungen' => [['id' => 'em-1', 'title' => 'x'], ['id' => 'em-2', 'title' => 'Zugausfall RB60', 'ki_titel' => 'RB60 16:38 fällt aus', 'ki_text' => 'Nächster Zug 16:52.'], ['id' => 'em-3', 'title' => 'leise', 'stumm' => true]]];

    // Neue Stoerung B ohne KI-Text: wartet. Eilmeldung em-2 kommt sofort, em-3 ist stumm.
    $ev = nb_push_events_data($alt, $neu, $state, $now);
    t_eq(['eil:em-2'], array_column($ev, 'id'), 'nur die Eilmeldung, B wartet auf den Text');
    t_eq('Eilmeldung: RB60 16:38 fällt aus', $ev[0]['title'], 'Eilmeldung mit KI-Titel');
    t_eq($now, $state['pending']['B'], 'B vorgemerkt');

    // KI-Text da: B wird gemeldet
    $neu2 = $neu;
    $neu2['stoerungen'][0]['ki_titel'] = 'Alte Störung';
    $neu2['stoerungen'][1]['ki_titel'] = 'Signal kaputt in Elmshorn';
    $neu2['stoerungen'][1]['ki_text'] = 'Mit Verspätungen ist zu rechnen.';
    $ev = nb_push_events_data($neu, $neu2, $state, $now + 120);
    t_eq(['stoerung:B'], array_column($ev, 'id'), 'B mit KI-Text');
    t_eq('Störung: Signal kaputt in Elmshorn', $ev[0]['title'], 'Titel aus KI');
    t_eq([], $state['pending'], 'nichts mehr vorgemerkt');

    // Ohne KI-Text nach sechs Minuten trotzdem melden
    $state2 = [];
    nb_push_events_data($alt, $neu, $state2, $now);
    $ev = nb_push_events_data($neu, $neu, $state2, $now + NB_PUSH_KI_WARTEN);
    t_eq('Störung: Signalstörung', $ev[0]['title'], 'technischer Titel nach Wartezeit');

    // Aufgehoben: nur wenn vorher gemeldet
    $state3 = ['sent' => ['stoerung:A' => $now - 100]];
    $ev = nb_push_events_data($neu2, ['stoerungen' => [$neu2['stoerungen'][1]], 'eilmeldungen' => $neu2['eilmeldungen']], $state3, $now + 300);
    t_eq(['aufgehoben:A'], array_column($ev, 'id'), 'Entwarnung fuer A');
    t_eq('Aufgehoben: Alte Störung', $ev[0]['title'], 'Entwarnung mit KI-Titel');
    $state4 = [];
    $ev = nb_push_events_data($neu2, ['stoerungen' => [], 'eilmeldungen' => $neu2['eilmeldungen']], $state4, $now + 300);
    t_eq([], $ev, 'ohne vorherige Meldung keine Entwarnung');

    // Leere alte Historie: nichts als neu melden (erster Lauf)
    $state5 = [];
    t_eq([], nb_push_events_data(['stoerungen' => [], 'eilmeldungen' => []], ['stoerungen' => [], 'eilmeldungen' => [['id' => 'em-9', 'title' => 'x']]], $state5, $now), 'erster Lauf');
}

function test_push_hinweise_ohne_folgen()
{
    $now = 1000000;
    $leer = ['stoerungen' => [], 'eilmeldungen' => []];
    $hinweis = ['key' => 'H', 'titel' => 'Signalstörung', 'ki_titel' => 'Eidelstedt: läuft wieder', 'ki_text' => 'Keine Folgen.', 'ki_folgen' => 'keine'];

    // Neuer Hinweis: nichts, auch nicht nach der Wartezeit; Aufhebung ebenfalls still
    $state = [];
    t_eq([], nb_push_events_data($leer, ['stoerungen' => [$hinweis]] + $leer, $state, $now), 'neuer Hinweis still');
    t_eq([], nb_push_events_data(['stoerungen' => [$hinweis]] + $leer, ['stoerungen' => [$hinweis]] + $leer, $state, $now + NB_PUSH_KI_WARTEN), 'auch spaeter still');
    t_eq(true, $state['hinweis']['H'], 'als Hinweis gemerkt');
    t_eq([], nb_push_events_data(['stoerungen' => [$hinweis]] + $leer, $leer, $state, $now + 900), 'Aufhebung eines Hinweises still');
    t_eq([], $state['hinweis'], 'Hinweis vergessen');

    // Aus dem Hinweis wird eine Stoerung: wie neu melden
    $state = [];
    nb_push_events_data($leer, ['stoerungen' => [$hinweis]] + $leer, $state, $now);
    $stoerung = ['ki_folgen' => 'verspaetungen', 'ki_titel' => 'Eidelstedt: Verspätungen'] + $hinweis;
    $ev = nb_push_events_data(['stoerungen' => [$hinweis]] + $leer, ['stoerungen' => [$stoerung]] + $leer, $state, $now + 300);
    t_eq(['stoerung:H'], array_column($ev, 'id'), 'Hinweis wird Stoerung: gemeldet');
    t_eq('Störung: Eidelstedt: Verspätungen', $ev[0]['title'], 'mit neuem Titel');

    // Gemeldete Stoerung wird Hinweis: Entwarnung sofort, spaetere Aufhebung nicht noch einmal
    $state = ['sent' => ['stoerung:H' => $now]];
    $ev = nb_push_events_data(['stoerungen' => [$stoerung]] + $leer, ['stoerungen' => [$hinweis]] + $leer, $state, $now + 600);
    t_eq(['aufgehoben:H'], array_column($ev, 'id'), 'Entwarnung beim Wechsel zum Hinweis');
    t_eq('Keine Folgen mehr: Eidelstedt: läuft wieder', $ev[0]['title'], 'Titel der Entwarnung');
    t_eq('Keine Folgen.', $ev[0]['body'], 'Text der Entwarnung');
    t_eq([], nb_push_events_data(['stoerungen' => [$hinweis]] + $leer, ['stoerungen' => [$hinweis]] + $leer, $state, $now + 700), 'bleibt still');
    // Wird sie danach wieder zur Stoerung, bekommt die Meldung eine neue Kennung (Sperre gegen Doppelte)
    $ev = nb_push_events_data(['stoerungen' => [$hinweis]] + $leer, ['stoerungen' => [$stoerung]] + $leer, $state, $now + 800);
    t_eq(['stoerung:H:' . ($now + 800)], array_column($ev, 'id'), 'erneut Stoerung: neue Kennung');
}

function test_push_ereignisse_aus_abfahrten()
{
    $now = (new DateTime('2026-10-15 07:00', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $result = ['richtungen' => [
        'hin' => [
            ['zug' => '81871', 'zeit' => '2026-10-15T07:18:00+02:00', 'linie' => 'RB 71', 'ausfall' => true, 'ab' => null, 'hinweis' => 'Defektes Stellwerk', 'ersatz' => null],
            ['zug' => '75515', 'zeit' => '2026-10-15T07:40:00+02:00', 'linie' => 'RB 61', 'ausfall' => false, 'ab' => null, 'hinweis' => '', 'ersatz' => null],
            ['zug' => '99', 'zeit' => '2026-10-15T09:40:00+02:00', 'linie' => 'RB 61', 'ausfall' => true, 'ab' => null, 'hinweis' => '', 'ersatz' => null],
        ],
        'rueck' => [
            ['zug' => '75514', 'zeit' => '2026-10-15T07:56:00+02:00', 'linie' => 'RB 61', 'ausfall' => true, 'ab' => 'Hbf', 'hinweis' => '', 'ersatz' => ['ab' => 'Altona', 'zeit' => '2026-10-15T08:04:00+02:00']],
        ],
    ]];
    $ev = nb_push_events_abfahrten($result, 'APD', $now);
    t_eq(['zug:APD:2026-10-15:81871', 'zug:APD:2026-10-15:75514'], array_column($ev, 'id'), 'zwei Ausfaelle im Fenster, der in 2:40 Std. nicht');
    t_eq('Zugausfall RB 71 um 07:18 Uhr', $ev[0]['title'], 'Titel hin');
    t_eq('Prisdorf → Hamburg. Defektes Stellwerk', $ev[0]['body'], 'Text hin');
    t_eq('Hamburg → Wrist / Itzehoe ab Hbf. Fährt stattdessen ab Altona um 08:04 Uhr', $ev[1]['body'], 'Text rueck mit Ersatz');
    t_eq('APD', $ev[1]['heimat'], 'Ereignis gilt nur fuer diesen Heimatbahnhof');
}

function test_push_run_verschickt_und_filtert()
{
    @unlink(NB_PUSH_SUBS_FILE);
    @unlink(NB_PUSH_STATE_FILE);
    $key = wp_new_keypair();
    openssl_pkey_export($key, $pem);
    // VAPID per Umgebung: nordbahn_config() liest config.php, die es im Test nicht gibt.
    // Darum laeuft dieser Test ueber die Konfiguration im Cache-Verzeichnis nicht, sondern
    // prueft den Ablauf mit einem eigenen Sender und eingespielten Abos.
    $abo = function ($endpoint, $heimat, $zeit) use ($key) {
        return nb_push_subscribe(['endpoint' => $endpoint, 'p256dh' => wp_b64url_encode(wp_raw_public_key($key)), 'auth' => wp_b64url_encode(random_bytes(16))], $heimat, $zeit);
    };
    t_eq(true, $abo('https://push.example/a', 'APD', 'immer'), 'Abo a');
    t_eq(true, $abo('https://push.example/b', 'AEL', 'immer'), 'Abo b');
    t_eq(true, $abo('https://push.example/c', 'APD', 'pendel'), 'Abo c');
    $subs = nordbahn_read_json(NB_PUSH_SUBS_FILE);
    t_eq(3, count($subs), 'drei Abos gespeichert');

    // Erneuerung: neuer Endpunkt uebernimmt Heimat und Zeit des alten
    t_eq(true, nb_push_subscribe(['endpoint' => 'https://push.example/c2', 'p256dh' => wp_b64url_encode(wp_raw_public_key($key)), 'auth' => wp_b64url_encode(random_bytes(16))], '', '', 'https://push.example/c'), 'Erneuerung');
    $subs = nordbahn_read_json(NB_PUSH_SUBS_FILE);
    t_eq(3, count($subs), 'weiter drei Abos');
    t_eq('pendel', $subs[nb_push_sub_id('https://push.example/c2')]['zeit'], 'Zeitregel uebernommen');

    if (nb_push_vapid() === null) {
        nb_push_unsubscribe('https://push.example/c2');
        t_eq(2, count(nordbahn_read_json(NB_PUSH_SUBS_FILE)), 'Abo entfernt');
        @unlink(NB_PUSH_SUBS_FILE);

        return; // Versand braucht VAPID aus config.php; der Rest ist oben geprueft
    }
}

function test_push_run_mit_vapid()
{
    // Versandlogik mit Stub-Sender: Heimat-Filter, Pendelzeit, tote Abos, Dedupe
    @unlink(NB_PUSH_SUBS_FILE);
    @unlink(NB_PUSH_STATE_FILE);
    $key = wp_new_keypair();
    openssl_pkey_export($key, $pem);
    $GLOBALS['t_vapid'] = ['private_pem' => $pem, 'public' => wp_vapid_public_from_private($pem), 'subject' => 'mailto:test@example.org'];
    $mk = function ($endpoint, $heimat, $zeit) use ($key) {
        nb_push_subscribe(['endpoint' => $endpoint, 'p256dh' => wp_b64url_encode(wp_raw_public_key($key)), 'auth' => wp_b64url_encode(random_bytes(16))], $heimat, $zeit);
    };
    $mk('https://push.example/apd-immer', 'APD', 'immer');
    $mk('https://push.example/ael-immer', 'AEL', 'immer');
    $mk('https://push.example/apd-pendel', 'APD', 'pendel');
    $mk('https://push.example/tot', 'APD', 'immer');

    $now = (new DateTime('2026-10-15 12:00', new DateTimeZone('Europe/Berlin')))->getTimestamp(); // keine Pendelzeit
    $alt = ['stoerungen' => [], 'eilmeldungen' => [['id' => 'em-1']]];
    $neu = ['stoerungen' => [], 'eilmeldungen' => [['id' => 'em-1'], ['id' => 'em-2', 'title' => 'Zugausfall RB60', 'ki_titel' => 'RB60 fällt aus']]];
    $abf = function ($heimat) {
        return $heimat === 'APD' ? ['richtungen' => ['hin' => [['zug' => '1', 'zeit' => '2026-10-15T12:18:00+02:00', 'linie' => 'RB 71', 'ausfall' => true, 'ab' => null, 'hinweis' => '', 'ersatz' => null]], 'rueck' => []]] : ['richtungen' => ['hin' => [], 'rueck' => []]];
    };
    $log = [];
    $sender = function ($sub, $payload, $vapid) use (&$log) {
        $log[] = [$sub['endpoint'], json_decode($payload, true)['tag']];

        return ['status' => strpos($sub['endpoint'], 'tot') !== false ? 410 : 201];
    };
    $bilanz = nb_push_run_mit($alt, $neu, $now, $abf, $sender, $GLOBALS['t_vapid']);
    t_eq(2, $bilanz['ereignisse'], 'Eilmeldung und ein Zugausfall');
    $gesendet = array_map(function ($l) { return $l[0] . ' ' . $l[1]; }, $log);
    sort($gesendet);
    t_eq([
        'https://push.example/ael-immer eil:em-2',
        'https://push.example/apd-immer eil:em-2',
        'https://push.example/apd-immer zug:APD:2026-10-15:1',
        'https://push.example/tot eil:em-2',
    ], $gesendet, 'Pendel-Abo mittags nicht, AEL keinen Prisdorfer Ausfall, totes Abo nur einmal versucht');
    t_eq(3, count(nordbahn_read_json(NB_PUSH_SUBS_FILE)), 'totes Abo entfernt');

    // Zweiter Lauf mit gleichen Daten: nichts doppelt
    $log = [];
    $bilanz = nb_push_run_mit($neu, $neu, $now + 120, $abf, $sender, $GLOBALS['t_vapid']);
    t_eq(0, $bilanz['ereignisse'], 'keine neuen Ereignisse');
    t_eq([], $log, 'nichts verschickt');
    @unlink(NB_PUSH_SUBS_FILE);
    @unlink(NB_PUSH_STATE_FILE);
}

function test_push_meine_zuege_bereinigung()
{
    $zuege = nb_push_clean_zuege([
        ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => '81871', 'linie' => 'RB 71', 'zeit' => '07:18', 'ziel' => 'Hamburg Hbf', 'tage' => [1, '3', 5, 9, 'x']],
        ['heimat' => 'APD', 'richtung' => 'rueck', 'zug' => '75514', 'linie' => 'RB 61', 'zeit' => '17:04', 'ab' => 'Hbf', 'ziel' => "Itzehoe\n"],
        ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => '81871', 'zeit' => '07:18'], // doppelt
        ['heimat' => 'XXX', 'richtung' => 'hin', 'zug' => '1', 'zeit' => '07:18'],
        ['heimat' => 'APD', 'richtung' => 'quer', 'zug' => '1', 'zeit' => '07:18'],
        ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => 'abc', 'zeit' => '07:18'],
        ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => ['1'], 'zeit' => '25:00'],
        'quatsch',
    ]);
    t_eq(2, count($zuege), 'zwei gueltige Zuege, der doppelte zaehlt einmal');
    t_eq([1, 3, 5], $zuege[0]['tage'], 'Wochentage bereinigt');
    t_eq('', $zuege[0]['ab'], 'hin ohne Startbahnhof');
    t_eq([1, 2, 3, 4, 5], $zuege[1]['tage'], 'ohne Tage Mo-Fr');
    t_eq('Itzehoe', $zuege[1]['ziel'], 'Steuerzeichen raus');
    t_eq(null, nb_push_clean_zuege(null), 'keine Liste: alte bleibt');
    $viele = [];
    for ($i = 1; $i <= 20; $i++) {
        $viele[] = ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => (string) $i, 'zeit' => '07:18'];
    }
    t_eq(NB_PUSH_MAX_ZUEGE, count(nb_push_clean_zuege($viele)), 'hoechstens NB_PUSH_MAX_ZUEGE');
}

/** Abfahrten-Antwort fuer die Tests zu gemerkten Zuegen. */
function t_meine_result(array $hin = [], array $rueck = [])
{
    $basis = ['zug' => '', 'zeit' => '', 'zeit_plan' => null, 'ist' => null, 'verspaetung' => 0, 'linie' => 'RB 61', 'ziel' => 'Hamburg Hbf',
        'gleis' => '1', 'gleis_plan' => null, 'ausfall' => false, 'bus' => false, 'hinweis' => '', 'ab' => null, 'ersatz' => null,
        'teil' => null, 'ersatz_fuer' => null, 'dammtor' => null];
    $mk = function ($rows) use ($basis) {
        return array_map(function ($r) use ($basis) { return $r + $basis; }, $rows);
    };

    return ['ok' => true, 'richtungen' => ['hin' => $mk($hin), 'rueck' => $mk($rueck)]];
}

function test_push_meine_zuege_ereignisse()
{
    $now = (new DateTime('2026-10-15 06:40', new DateTimeZone('Europe/Berlin')))->getTimestamp(); // Donnerstag
    $hin = ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => '75515', 'linie' => 'RB 61', 'zeit' => '07:12', 'ab' => '', 'ziel' => 'Hamburg Hbf', 'tage' => [1, 2, 3, 4, 5]];
    $zuege = ['APD:hin:75515' => $hin];
    $state = ['sent' => []];

    // Puenktlich: nichts
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:12:00+02:00']]);
    t_eq([], nb_push_events_meine($res, $zuege, $state, $now), 'puenktlich still');

    // +4: noch unter der Schwelle
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:16:00+02:00', 'verspaetung' => 4]]);
    t_eq([], nb_push_events_meine($res, $zuege, $state, $now), '+4 still');

    // +7 mit Gleiswechsel: eine Meldung, Titel nach der Verspaetung
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:19:00+02:00', 'verspaetung' => 7, 'gleis' => '2', 'gleis_plan' => '1', 'hinweis' => 'Verspätung aus vorheriger Fahrt']]);
    $ev = nb_push_events_meine($res, $zuege, $state, $now);
    t_eq(1, count($ev), 'eine Meldung fuer Verspaetung und Gleis');
    t_eq('RB 61 um 07:12: +7 Min.', $ev[0]['title'], 'Titel');
    t_eq('Dein Zug Prisdorf → Hamburg Hbf. Voraussichtlich 07:19 Uhr statt 07:12 (+7). Heute Gleis 2 statt 1. Verspätung aus vorheriger Fahrt.', $ev[0]['body'], 'Text');
    t_eq('mein:APD:hin:75515:2026-10-15', $ev[0]['tag'], 'ein tag je Zug und Tag');
    t_eq(['mein:APD:hin:75515:2026-10-15:plus:5', 'mein:APD:hin:75515:2026-10-15:gleis:2'], $ev[0]['teile'], 'Teile');
    t_eq(4, $ev[0]['wochentag'], 'Donnerstag');
    foreach ($ev[0]['teile'] as $t) {
        $state['sent'][$t] = $now;
    }

    // +9 bleibt in der Stufe, +12 meldet sich erneut, danach +6 still
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:21:00+02:00', 'verspaetung' => 9, 'gleis' => '2', 'gleis_plan' => '1']]);
    t_eq([], nb_push_events_meine($res, $zuege, $state, $now + 120), '+9 gleiche Stufe');
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:24:00+02:00', 'verspaetung' => 12, 'gleis' => '2', 'gleis_plan' => '1']]);
    $ev = nb_push_events_meine($res, $zuege, $state, $now + 240);
    t_eq(['mein:APD:hin:75515:2026-10-15:plus:10'], array_column($ev, 'id'), '+12 naechste Stufe');
    $state['sent'][$ev[0]['teile'][0]] = $now;
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:18:00+02:00', 'verspaetung' => 6, 'gleis' => '2', 'gleis_plan' => '1']]);
    t_eq([], nb_push_events_meine($res, $zuege, $state, $now + 360), 'kleiner werdende Verspaetung still');

    // Mehr als eine Stunde vorher und nach der Abfahrt: nichts
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T08:12:00+02:00', 'ist' => '2026-10-15T08:30:00+02:00', 'verspaetung' => 18]]);
    t_eq([], nb_push_events_meine($res, $zuege, ['sent' => []], $now), 'zu frueh');
    $res = t_meine_result([['zug' => '75515', 'zeit' => '2026-10-15T06:30:00+02:00', 'ist' => '2026-10-15T06:37:00+02:00', 'verspaetung' => 7]]);
    t_eq([], nb_push_events_meine($res, $zuege, ['sent' => []], $now), 'schon weg');

    // Neue Zugnummer nach dem Fahrplanwechsel: Linie und Planzeit reichen
    $res = t_meine_result([['zug' => '99999', 'zeit' => '2026-10-15T07:12:00+02:00', 'ausfall' => true, 'hinweis' => 'Personalmangel']]);
    $ev = nb_push_events_meine($res, $zuege, ['sent' => []], $now);
    t_eq('RB 61 um 07:12 fällt aus', $ev[0]['title'] ?? null, 'Ausfall ueber Planzeit gefunden');
    t_eq('Dein Zug Prisdorf → Hamburg Hbf. Fällt heute aus. Personalmangel.', $ev[0]['body'], 'Ausfalltext');
}

function test_push_meine_zuege_ersatz_und_teil()
{
    $now = (new DateTime('2026-10-15 16:40', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $rueck = ['heimat' => 'APD', 'richtung' => 'rueck', 'zug' => '75514', 'linie' => 'RB 61', 'zeit' => '17:04', 'ab' => 'Hbf', 'ziel' => 'Itzehoe', 'tage' => [4]];
    $zuege = ['APD:rueck:75514' => $rueck];
    $state = ['sent' => []];

    // Erst nur Ausfall am Hbf, dann kommt der Ersatz ab Altona dazu
    $res = t_meine_result([], [['zug' => '75514', 'zeit' => '2026-10-15T17:04:00+02:00', 'ausfall' => true, 'ab' => 'Hbf', 'ziel' => 'Itzehoe']]);
    $ev = nb_push_events_meine($res, $zuege, $state, $now);
    t_eq('RB 61 um 17:04 fällt aus', $ev[0]['title'], 'erst Ausfall');
    t_eq('Dein Zug Hamburg Hbf → Prisdorf. Fällt heute aus.', $ev[0]['body'], 'Ausfalltext rueck');
    $state['sent'][$ev[0]['teile'][0]] = $now;

    $res = t_meine_result([], [
        ['zug' => '75514', 'zeit' => '2026-10-15T17:04:00+02:00', 'ausfall' => true, 'ab' => 'Hbf', 'ziel' => 'Itzehoe',
            'ersatz' => ['ab' => 'Altona', 'zeit' => '2026-10-15T17:13:00+02:00', 'ist' => null, 'verspaetung' => 0, 'gleis' => '5', 'gleis_plan' => null]],
        ['zug' => '75514', 'zeit' => '2026-10-15T17:13:00+02:00', 'ab' => 'Altona', 'ziel' => 'Itzehoe', 'gleis' => '5',
            'ersatz_fuer' => ['ab' => 'Hbf', 'zeit' => '2026-10-15T17:04:00+02:00']],
    ]);
    $ev = nb_push_events_meine($res, $zuege, $state, $now + 120);
    t_eq(1, count($ev), 'Ersatz meldet sich, nur einmal trotz zwei Zeilen');
    t_eq('RB 61 17:04 fährt heute ab Altona', $ev[0]['title'], 'Titel Ersatz');
    t_eq('Dein Zug Hamburg Hbf → Prisdorf. Fällt ab Hbf aus, fährt stattdessen ab Altona um 17:13 Uhr, Gleis 5.', $ev[0]['body'], 'Text Ersatz');

    // Startet erst in Pinneberg (aus der Zugauskunft umgebaute Zeile)
    $res = t_meine_result([], [['zug' => '75514', 'zeit' => '2026-10-15T17:31:00+02:00', 'zeit_plan' => '2026-10-15T17:04:00+02:00', 'ab' => 'Hbf', 'gleis' => '2',
        'teil' => ['art' => 'beginnt', 'id' => 'AP', 'name' => 'Pinneberg', 'zeit' => '17:31']]]);
    $ev = nb_push_events_meine($res, $zuege, ['sent' => []], $now);
    t_eq('RB 61 17:04 startet erst in Pinneberg', $ev[0]['title'], 'Titel Teil');
    t_eq('Dein Zug Hamburg Hbf → Prisdorf. Abfahrt dort um 17:31 Uhr, Gleis 2.', $ev[0]['body'], 'Text Teil');

    // Einstieg am Dammtor: Werte des Dammtor-Halts
    $damm = ['APD:rueck:75514' => ['ab' => 'Dammtor'] + $rueck];
    $res = t_meine_result([], [['zug' => '75514', 'zeit' => '2026-10-15T17:04:00+02:00', 'ab' => 'Hbf', 'gleis' => '8',
        'dammtor' => ['zeit' => '2026-10-15T17:07:00+02:00', 'ist' => '2026-10-15T17:13:00+02:00', 'verspaetung' => 6, 'gleis' => '3', 'gleis_plan' => null, 'ausfall' => false]]]);
    $ev = nb_push_events_meine($res, $damm, ['sent' => []], $now);
    t_eq('RB 61 um 17:07: +6 Min.', $ev[0]['title'], 'Dammtor-Zeit und -Verspaetung');
}

function test_push_run_meine_zuege()
{
    @unlink(NB_PUSH_SUBS_FILE);
    @unlink(NB_PUSH_STATE_FILE);
    $key = wp_new_keypair();
    openssl_pkey_export($key, $pem);
    $vapid = ['private_pem' => $pem, 'public' => wp_vapid_public_from_private($pem), 'subject' => 'mailto:test@example.org'];
    $mk = function ($endpoint, $heimat, $zeit, $zuege, $nur) use ($key) {
        nb_push_subscribe(['endpoint' => $endpoint, 'p256dh' => wp_b64url_encode(wp_raw_public_key($key)), 'auth' => wp_b64url_encode(random_bytes(16))], $heimat, $zeit, '', nb_push_clean_zuege($zuege), $nur);
    };
    $zug = ['heimat' => 'APD', 'richtung' => 'hin', 'zug' => '75515', 'linie' => 'RB 61', 'zeit' => '07:12', 'ziel' => 'Hamburg Hbf'];
    $mk('https://push.example/nur', 'AEL', 'pendel', [$zug], true);              // nur dieser Zug, Heimat egal
    $mk('https://push.example/alles', 'APD', 'pendel', [$zug + ['tage' => [4]]], false);
    $mk('https://push.example/montags', 'AEL', 'immer', [$zug + ['tage' => [1]]], true);
    $mk('https://push.example/ohne', 'APD', 'immer', [], false);

    // Erneuerung ohne Zuege laesst sie stehen
    nb_push_subscribe(['endpoint' => 'https://push.example/nur', 'p256dh' => wp_b64url_encode(wp_raw_public_key($key)), 'auth' => wp_b64url_encode(random_bytes(16))], 'AEL', '');
    $subs = nordbahn_read_json(NB_PUSH_SUBS_FILE);
    t_eq(1, count($subs[nb_push_sub_id('https://push.example/nur')]['zuege']), 'Zuege bleiben ohne Angabe');
    t_eq(true, $subs[nb_push_sub_id('https://push.example/nur')]['nur'], 'nur bleibt');

    $now = (new DateTime('2026-10-15 06:40', new DateTimeZone('Europe/Berlin')))->getTimestamp(); // Donnerstag, Pendelzeit
    $gefragt = [];
    $abf = function ($heimat) use (&$gefragt) {
        $gefragt[] = $heimat;

        return $heimat === 'APD' ? t_meine_result([
            ['zug' => '75515', 'zeit' => '2026-10-15T07:12:00+02:00', 'ist' => '2026-10-15T07:20:00+02:00', 'verspaetung' => 8],
            ['zug' => '81871', 'zeit' => '2026-10-15T07:18:00+02:00', 'linie' => 'RB 71', 'ausfall' => true],
        ]) : t_meine_result();
    };
    $log = [];
    $sender = function ($sub, $payload, $vapid) use (&$log) {
        $p = json_decode($payload, true);
        $log[] = $sub['endpoint'] . ' ' . $p['tag'];

        return ['status' => 201];
    };
    nb_push_run_mit([], [], $now, $abf, $sender, $vapid);
    sort($log);
    t_eq([
        'https://push.example/alles mein:APD:hin:75515:2026-10-15',
        'https://push.example/alles zug:APD:2026-10-15:81871',
        'https://push.example/nur mein:APD:hin:75515:2026-10-15',
        'https://push.example/ohne zug:APD:2026-10-15:81871',
    ], $log, 'Zugmeldung nur an Abos mit dem Zug und passendem Tag, "nur" ohne Ausfall am Bahnhof');
    sort($gefragt);
    t_eq(['APD'], $gefragt, 'Prisdorf einmal gelesen, Elmshorn gar nicht (nur Abos mit "nur")');

    $log = [];
    nb_push_run_mit([], [], $now + 120, $abf, $sender, $vapid);
    t_eq([], $log, 'nichts doppelt');
    @unlink(NB_PUSH_SUBS_FILE);
    @unlink(NB_PUSH_STATE_FILE);
}

function test_push_meine_zuege_varianten()
{
    $zuege = nb_push_clean_zuege([['heimat' => 'APD', 'richtung' => 'hin', 'zug' => '91818', 'nummern' => ['91818', '81817', 'x', '1', '2', '3', '4'], 'linie' => 'RB 71', 'zeit' => '08:18', 'ziel' => 'Hamburg-Altona']]);
    t_eq(['91818', '81817', '1', '2', '3'], $zuege[0]['nummern'], 'eigene Nummer zuerst, nur Ziffern, hoechstens vier weitere');
    t_eq(['5'], nb_push_clean_zuege([['heimat' => 'APD', 'richtung' => 'hin', 'zug' => '5', 'zeit' => '08:18']])[0]['nummern'], 'ohne Varianten nur die eigene');

    // Nach dem Baufahrplan faehrt wieder der 08:17 zum Hbf: der gemerkte 08:18 meldet sich trotzdem
    $now = (new DateTime('2026-10-26 07:40', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $z = $zuege[0];
    $res = t_meine_result([['zug' => '81817', 'linie' => 'RB 71', 'zeit' => '2026-10-26T08:17:00+01:00', 'ausfall' => true]]);
    $ev = nb_push_events_meine($res, [nb_push_zug_key($z) => $z], ['sent' => []], $now);
    t_eq('RB 71 um 08:17 fällt aus', $ev[0]['title'] ?? null, 'Variante ueber ihre Nummer gefunden');
}
