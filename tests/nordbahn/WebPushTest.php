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
