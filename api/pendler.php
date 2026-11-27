<?php
/**
 * Pendlermeldungen: kurze Texte von Fahrgaesten zu dem, was keine Quelle meldet
 * (Durchsagen, wo der Ersatzbus haelt, kaputter Aufzug, ein guter Tipp). Jede
 * Meldung hat ein Thema, einen Bahnhof und optional eine Zugnummer, ist ohne Konto
 * und laeuft nach dem Thema entsprechender Zeit von selbst ab.
 *
 * URL: /api/pendler
 *   GET   laufende, freigegebene Meldungen:
 *         {"ok":true,"an":true,"meldungen":[{"id","thema","bhf","bhf_name","zug","text",
 *          "zeit","bis","stimmt"}]}
 *   POST  aktion=schreiben: thema, bhf, zug (optional), text, geheim (Zufallswert des
 *         Browsers, erlaubt spaeter das Loeschen), website (Honeypot)
 *         aktion=stimmt | stimmt_nicht | unangemessen: id
 *         aktion=loeschen: id, geheim
 *         Antwort {"ok":bool,"status":"...","grund":"...","id":"...","meldungen":[...]}
 *         Status beim Schreiben: frei (sichtbar), wartet (Pruefung nicht erreichbar,
 *         Freigabe von Hand), abgelehnt (mit grund), gesperrt, limit, ungueltig
 *   /pendler/verwalten/?id=..&sig=..  Seite fuer den Betreiber (Link aus Discord) mit
 *         Freigeben und Entfernen. sig ist ein HMAC ueber die ID mit NORDBAHN_PUSH_TOKEN.
 *
 * Pruefung vor dem Veroeffentlichen, in dieser Reihenfolge:
 *   1. feste Regeln hier (nb_pd_regeln): Laenge, keine Links, Telefonnummern oder
 *      E-Mail-Adressen, nichts zu Fahrkartenkontrollen, keine groben Beleidigungen,
 *      derselbe Text nicht doppelt
 *   2. KI ueber einen n8n-Webhook (NORDBAHN_PENDLER_PRUEFUNG, Header X-Nordbahn-Token
 *      mit NORDBAHN_PUSH_TOKEN): {"ok":true} oder {"ok":false,"grund":"person"}. Die KI
 *      urteilt nur und formuliert nichts um. Ist der Webhook nicht eingerichtet, gelten
 *      nur die Regeln und die Meldung ist sofort sichtbar. Ist er eingerichtet, aber
 *      nicht erreichbar oder sind schon NB_PD_KI_MAX Pruefungen in der Stunde gelaufen,
 *      wartet die Meldung auf Freigabe von Hand.
 * Danach geht jede Meldung mit Verwaltungslink an Discord (NORDBAHN_PENDLER_WEBHOOK,
 * sonst der Feedback-Webhook). "Unangemessen" von NB_PD_SCHWELLE verschiedenen
 * IP-Adressen blendet eine Meldung bis zur Entscheidung aus, "Stimmt nicht mehr"
 * ebenso oft beendet sie. "Stimmt noch" verlaengert um die Laufzeit des Themas, hoechstens
 * bis NB_PD_MAX_LAUFZEIT nach dem Schreiben.
 *
 * Gespeichert wird eine Datei cache/pendler/meldungen.json mit Text, Thema, Ort, Zeiten
 * und Tageskennungen (Hash, wie beim Feedback); Eintraege aelter als NB_PD_AUFBEWAHREN
 * fliegen beim naechsten Schreiben raus. Aus mit NORDBAHN_PENDLER => false.
 */

require_once __DIR__ . '/store.php';
if (!function_exists('nb_feedback_kennung')) {
    if (!defined('NORDBAHN_FEEDBACK_LIB')) {
        define('NORDBAHN_FEEDBACK_LIB', true);
    }
    require_once __DIR__ . '/feedback.php';
}
if (!function_exists('nb_cm_bekannte_zuege')) {
    if (!defined('NORDBAHN_COMMUNITY_LIB')) {
        define('NORDBAHN_COMMUNITY_LIB', true);
    }
    require_once __DIR__ . '/community.php';
}

/** Themen: Wortlaut und Laufzeit in Minuten. */
const NB_PD_THEMEN = [
    'lage'    => ['text' => 'Lage unterwegs', 'minuten' => 60],
    'sev'     => ['text' => 'Ersatzverkehr',  'minuten' => 240],
    'bahnhof' => ['text' => 'Am Bahnhof',     'minuten' => 720],
    'tipp'    => ['text' => 'Tipp',           'minuten' => 60],
];
/** Orte: die zwoelf Heimatbahnhoefe und die drei Hamburger Bahnhoefe. */
const NB_PD_HAMBURG = ['AH' => 'Hamburg Hbf', 'ADF' => 'Dammtor', 'AA' => 'Altona'];
const NB_PD_MIN          = 10;
const NB_PD_MAX          = 200;
const NB_PD_MAX_LAUFZEIT = 24 * 3600;     // auch mit "Stimmt noch" nie laenger
const NB_PD_AUFBEWAHREN  = 7 * 86400;     // dann ist auch der Text weg
const NB_PD_SCHWELLE     = 2;             // verschiedene IPs fuer "unangemessen" bzw. "stimmt nicht mehr"
const NB_PD_LIMIT        = 3;             // neue Meldungen je Besucher ...
const NB_PD_LIMIT_IP     = 10;            // ... und je IP-Adresse ...
const NB_PD_FENSTER      = 3600;          // ... in diesem Zeitraum
const NB_PD_LIMIT_KLICK  = 30;            // Stimmt/Unangemessen je IP und Stunde
const NB_PD_SPERRE       = 3;             // abgelehnte oder entfernte Meldungen je Tageskennung bis zur Sperre
const NB_PD_KI_MAX       = 60;            // KI-Pruefungen je Stunde insgesamt (Kosten)
const NB_PD_KI_TIMEOUT   = 8;             // Sekunden
const NB_PD_DOPPELT      = 2 * 3600;      // gleicher Text in diesem Zeitraum gilt als doppelt
/** Gruende, die gegen die Tageskennung zaehlen; Tippfehler wie zu kurz oder eine Nummer nicht. */
const NB_PD_VERWARNEN    = ['link', 'kontrolle', 'beleidigung', 'person', 'werbung'];

/** Woerter, die ohne Kontext nie in eine Pendlermeldung gehoeren (Wortanfang). */
const NB_PD_SCHIMPF = ['arschloch', 'wichser', 'hurensohn', 'fotze', 'spast', 'missgeburt', 'schlampe', 'vollidiot', 'drecksau', 'kanake', 'neger'];

function nb_pd_dir()
{
    return NORDBAHN_CACHE_DIR . '/pendler';
}

function nb_pd_datei()
{
    return nb_pd_dir() . '/meldungen.json';
}

/** Ist die Funktion eingeschaltet? Vorgabe an. */
function nb_pd_an()
{
    return nordbahn_pendler_an();
}

/** Alle Orte, an die sich eine Meldung haengen laesst: ID => Name, in Streckenreihenfolge ab Hamburg. */
function nb_pd_orte()
{
    $orte = NB_PD_HAMBURG;
    foreach (NORDBAHN_BAHNHOEFE as $id => $b) {
        $orte[$id] = $b['name'];
    }

    return $orte;
}

/** Webhook der KI-Pruefung, leer wenn nicht eingerichtet. */
function nb_pd_pruefung_url()
{
    $url = trim((string) (nordbahn_config()['NORDBAHN_PENDLER_PRUEFUNG'] ?? ''));

    return preg_match('#^https://#', $url) ? $url : '';
}

/** Discord-Webhook fuer neue Meldungen: eigener Kanal, sonst der des Feedbacks. */
function nb_pd_discord_webhook()
{
    $url = trim((string) (nordbahn_config()['NORDBAHN_PENDLER_WEBHOOK'] ?? ''));
    if (preg_match('#^https://(discord\.com|discordapp\.com)/api/webhooks/#', $url)) {
        return $url;
    }

    return nordbahn_feedback_webhook();
}

function nb_pd_token()
{
    return (string) (nordbahn_config()['NORDBAHN_PUSH_TOKEN'] ?? '');
}

/** Signatur fuer den Verwaltungslink; leer ohne Push-Token (dann gibt es keine Verwaltung). */
function nb_pd_signatur($id, $token = null)
{
    $token = $token ?? nb_pd_token();

    return $token === '' ? '' : substr(hash_hmac('sha256', 'pendler|' . $id, $token), 0, 32);
}

function nb_pd_verwalten_url($id, $siteUrl, $token = null)
{
    return $siteUrl . '/pendler/verwalten/?id=' . rawurlencode($id) . '&sig=' . nb_pd_signatur($id, $token);
}

function nb_pd_iso($ts)
{
    return (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('c');
}

function nb_pd_tag($now)
{
    return (new DateTime('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
}

/** Text fuer Vergleiche: klein, nur Buchstaben und Ziffern. */
function nb_pd_normal($text)
{
    return preg_replace('/[^a-z0-9äöüß]+/u', '', mb_strtolower((string) $text));
}

/** Steuerzeichen und Zeilenumbrueche raus, Leerraum zusammen, lange Zeichenketten gekuerzt. */
function nb_pd_bereinigen($text)
{
    $text = nb_feedback_bereinigen($text, 1000);
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    // "!!!!!!!!" oder "nooooooo" auf drei Zeichen
    $text = preg_replace('/(.)\1{3,}/u', '$1$1$1', $text) ?? '';

    return trim($text);
}

/**
 * Feste Regeln. null = in Ordnung, sonst ein Grund: zu_kurz, zu_lang, link, kontakt,
 * kontrolle, beleidigung.
 */
function nb_pd_regeln($text)
{
    $n = mb_strlen($text);
    if ($n < NB_PD_MIN) {
        return 'zu_kurz';
    }
    if ($n > NB_PD_MAX) {
        return 'zu_lang';
    }
    if (preg_match('/[^\s@]+@[^\s@]+\.[a-z]{2,}/iu', $text)) {
        return 'kontakt';
    }
    if (preg_match('~https?://|www\.|\b[a-z0-9-]{2,}\.(de|com|net|org|info|io|eu|me|ly|app|link|shop)\b~iu', $text)) {
        return 'link';
    }
    // Telefonnummer: mindestens sieben Ziffern, hoechstens durch Leerzeichen, / oder - getrennt.
    // Uhrzeiten (7:42) und Zugnummern (20123) bleiben darunter.
    if (preg_match_all('~\+?\d[\d /-]{5,}\d~u', $text, $treffer)) {
        foreach ($treffer[0] as $t) {
            if (strlen(preg_replace('/\D/', '', $t)) >= 7) {
                return 'kontakt';
            }
        }
    }
    $klein = mb_strtolower($text);
    if (preg_match('/kontroll|kontrolettis?|fahrkartenpr|ticketpr|fahrscheinpr|schwarzfahr|sheriffs?\b|blaue? jacken/u', $klein)) {
        return 'kontrolle';
    }
    foreach (NB_PD_SCHIMPF as $wort) {
        if (preg_match('/(^|[^a-zäöüß])' . preg_quote($wort, '/') . '/u', $klein)) {
            return 'beleidigung';
        }
    }

    return null;
}

/**
 * Prueft eine Schreib-Anfrage (Herkunft, Felder, Regeln). Ergebnis wie bei den
 * Fahrgastmeldungen: ['code', 'status', 'grund'?, 'daten'?].
 */
function nb_pd_pruefen_schreiben(array $post, array $server, $siteUrl, array $bekannt)
{
    $basis = nb_feedback_pruefen(['nachricht' => 'Pendlermeldung'] + array_intersect_key($post, ['website' => 1]), $server, $siteUrl);
    if (in_array($basis['status'], ['origin', 'bot', 'honeypot'], true)) {
        return ['code' => $basis['code'], 'status' => $basis['status']];
    }
    $thema = (string) ($post['thema'] ?? '');
    $bhf = strtoupper((string) ($post['bhf'] ?? ''));
    $orte = nb_pd_orte();
    if (!isset(NB_PD_THEMEN[$thema]) || !isset($orte[$bhf])) {
        return ['code' => 400, 'status' => 'ungueltig'];
    }
    $geheim = strtolower((string) ($post['geheim'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32,64}$/', $geheim)) {
        $geheim = '';
    }
    // Eine Zugnummer nur, wenn der Zug gerade faehrt oder gleich abfaehrt; sonst ohne Zug
    $zug = preg_replace('/\D/', '', (string) ($post['zug'] ?? ''));
    if ($zug === '' || strlen($zug) > 6 || !isset($bekannt[$zug])) {
        $zug = null;
    }
    $text = nb_pd_bereinigen($post['text'] ?? '');
    $grund = nb_pd_regeln($text);
    if ($grund !== null) {
        return ['code' => 422, 'status' => 'abgelehnt', 'grund' => $grund];
    }

    return ['code' => 200, 'status' => 'ok', 'daten' => ['thema' => $thema, 'bhf' => $bhf, 'zug' => $zug, 'text' => $text, 'geheim' => $geheim]];
}

/** Gleicher Text (normalisiert) in den letzten NB_PD_DOPPELT Sekunden, egal von wem? */
function nb_pd_doppelt(array $daten, $text, $now)
{
    $norm = nb_pd_normal($text);
    foreach ($daten['meldungen'] ?? [] as $m) {
        if ($m['ts'] > $now - NB_PD_DOPPELT && nb_pd_normal($m['text']) === $norm) {
            return true;
        }
    }

    return false;
}

/** Ist die Tageskennung nach NB_PD_SPERRE Ablehnungen gesperrt? */
function nb_pd_gesperrt(array $daten, $kennung, $now)
{
    $s = $daten['sperre'][$kennung] ?? null;

    return is_array($s) && ($s['tag'] ?? '') === nb_pd_tag($now) && (int) ($s['anzahl'] ?? 0) >= NB_PD_SPERRE;
}

/** Zaehlt eine Ablehnung oder Entfernung gegen die Tageskennung. */
function nb_pd_verwarnen(array $daten, $kennung, $now)
{
    $tag = nb_pd_tag($now);
    $s = $daten['sperre'][$kennung] ?? null;
    $anzahl = is_array($s) && ($s['tag'] ?? '') === $tag ? (int) $s['anzahl'] : 0;
    $daten['sperre'][$kennung] = ['tag' => $tag, 'anzahl' => $anzahl + 1];

    return $daten;
}

/**
 * Darf noch eine KI-Pruefung laufen (Kostenbremse ueber alle Besucher)? Zaehlt sie
 * gleich mit. $daten['ki'] haelt die Zeitstempel der letzten Stunde.
 */
function nb_pd_ki_erlaubt(array $daten, $now)
{
    $ki = array_values(array_filter($daten['ki'] ?? [], function ($t) use ($now) {
        return (int) $t > $now - 3600;
    }));
    if (count($ki) >= NB_PD_KI_MAX) {
        return [$daten, false];
    }
    $ki[] = $now;
    $daten['ki'] = $ki;

    return [$daten, true];
}

/**
 * Fragt die KI-Pruefung in n8n. Ergebnis ['ok' => true], ['ok' => false, 'grund' => ...]
 * oder null (nicht eingerichtet, nicht erreichbar, unklare Antwort). $fetch fuer Tests:
 * function ($url, $json, $token) -> Antworttext|null.
 */
function nb_pd_ki_pruefen(array $m, $url, $token, ?callable $fetch = null)
{
    if ($url === '' || $token === '') {
        return null;
    }
    $orte = nb_pd_orte();
    $json = json_encode([
        'text'    => $m['text'],
        'thema'   => NB_PD_THEMEN[$m['thema']]['text'],
        'bahnhof' => $orte[$m['bhf']] ?? $m['bhf'],
        'zug'     => $m['zug'],
    ], JSON_UNESCAPED_UNICODE);
    $fetch = $fetch ?? function ($url, $json, $token) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => NB_PD_KI_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Nordbahn-Token: ' . $token],
        ]);
        $antwort = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($antwort === false || $http < 200 || $http >= 300) {
            error_log('pendler: KI-Pruefung antwortet HTTP ' . $http);

            return null;
        }

        return (string) $antwort;
    };
    $antwort = json_decode((string) $fetch($url, $json, $token), true);
    if (!is_array($antwort) || !is_bool($antwort['ok'] ?? null)) {
        return null;
    }
    if ($antwort['ok'] === true) {
        return ['ok' => true];
    }
    $grund = (string) ($antwort['grund'] ?? 'sonst');

    return ['ok' => false, 'grund' => in_array($grund, ['person', 'beleidigung', 'kontrolle', 'werbung', 'thema', 'sonst'], true) ? $grund : 'sonst'];
}

/** Neue ID: kurz, nicht erratbar genug, um fremde Meldungen ueber die ID zu treffen. */
function nb_pd_neue_id()
{
    return bin2hex(random_bytes(6));
}

/**
 * Status einer neuen Meldung nach der Vorpruefung: Ohne eingerichtete KI-Pruefung
 * (nur_regeln) ist sie sofort sichtbar, mit KI-Urteil ebenso. Ist die KI eingerichtet,
 * liefert aber kein Urteil oder ist die Kostenbremse erreicht, wartet sie auf Freigabe.
 */
function nb_pd_start_status($weg, $urteil)
{
    if ($weg === 'nur_regeln') {
        return 'frei';
    }

    return $urteil === null ? 'wartet' : 'frei';
}

/** Legt eine Meldung an. $status: frei oder wartet. */
function nb_pd_anlegen(array $daten, array $m, $kennung, $ipKennung, $status, $now)
{
    $id = nb_pd_neue_id();
    $daten['meldungen'][] = [
        'id'      => $id,
        'thema'   => $m['thema'],
        'bhf'     => $m['bhf'],
        'zug'     => $m['zug'],
        'text'    => $m['text'],
        'ts'      => $now,
        'bis'     => $now + NB_PD_THEMEN[$m['thema']]['minuten'] * 60,
        'status'  => $status,
        'kennung' => $kennung,
        'ip'      => $ipKennung,
        'geheim'  => $m['geheim'] !== '' ? hash('sha256', $m['geheim']) : '',
        'stimmt'  => [],
        'nein'    => [],
        'melden'  => [],
    ];

    return [$daten, $id];
}

/** Index der Meldung mit dieser ID oder null. */
function nb_pd_finden(array $daten, $id)
{
    foreach ($daten['meldungen'] ?? [] as $i => $m) {
        if (hash_equals((string) $m['id'], (string) $id)) {
            return $i;
        }
    }

    return null;
}

/** Laeuft die Meldung noch und ist sie freigegeben? */
function nb_pd_sichtbar(array $m, $now)
{
    return ($m['status'] ?? '') === 'frei' && (int) $m['bis'] > $now;
}

/**
 * Aktion eines Lesers auf eine Meldung: stimmt, stimmt_nicht, unangemessen, loeschen.
 * Gibt [daten, status] zurueck; status 'gemeldet' bei unangemessen heisst: jetzt
 * ausgeblendet, der Betreiber soll entscheiden.
 */
function nb_pd_aktion(array $daten, $id, $aktion, $kennung, $ipKennung, $geheim, $now)
{
    $i = nb_pd_finden($daten, $id);
    if ($i === null) {
        return [$daten, 'unbekannt'];
    }
    $m = &$daten['meldungen'][$i];
    if ($aktion === 'loeschen') {
        if (($m['geheim'] ?? '') === '' || !hash_equals($m['geheim'], hash('sha256', strtolower((string) $geheim)))) {
            return [$daten, 'verboten'];
        }
        $m['status'] = 'geloescht';

        return [$daten, 'geloescht'];
    }
    if (!nb_pd_sichtbar($m, $now)) {
        return [$daten, 'vorbei'];
    }
    if ($aktion === 'stimmt') {
        if (!isset($m['stimmt'][$kennung]) && $kennung !== $m['kennung']) {
            $m['stimmt'][$kennung] = $now;
        }
        $m['bis'] = min((int) $m['ts'] + NB_PD_MAX_LAUFZEIT, max((int) $m['bis'], $now + NB_PD_THEMEN[$m['thema']]['minuten'] * 60));

        return [$daten, 'bestaetigt'];
    }
    // stimmt_nicht und unangemessen zaehlen verschiedene IP-Adressen, damit ein Browserwechsel nicht reicht
    $feld = $aktion === 'stimmt_nicht' ? 'nein' : 'melden';
    $m[$feld][$ipKennung] = $now;
    if (count($m[$feld]) < NB_PD_SCHWELLE) {
        return [$daten, 'notiert'];
    }
    if ($feld === 'nein') {
        $m['bis'] = $now;

        return [$daten, 'beendet'];
    }
    $m['status'] = 'gemeldet';

    return [$daten, 'gemeldet'];
}

/** Entscheidung des Betreibers: freigeben (auch nach "unangemessen") oder entfernen. */
function nb_pd_entscheiden(array $daten, $id, $entscheidung, $now)
{
    $i = nb_pd_finden($daten, $id);
    if ($i === null) {
        return [$daten, 'unbekannt'];
    }
    $m = &$daten['meldungen'][$i];
    if ($entscheidung === 'freigeben') {
        $war = $m['status'];
        $m['status'] = 'frei';
        $m['melden'] = [];
        // Wer auf Freigabe gewartet hat, bekommt die volle Laufzeit ab jetzt
        if ($war === 'wartet') {
            $m['bis'] = min((int) $m['ts'] + NB_PD_MAX_LAUFZEIT, $now + NB_PD_THEMEN[$m['thema']]['minuten'] * 60);
        }

        return [$daten, 'freigegeben'];
    }
    if ($m['status'] !== 'entfernt') {
        $m['status'] = 'entfernt';
        $daten = nb_pd_verwarnen($daten, $m['kennung'], $now);
    }

    return [$daten, 'entfernt'];
}

/** Alte Eintraege raus (Text und Kennungen), Sperren von gestern ebenso. */
function nb_pd_aufraeumen(array $daten, $now)
{
    $daten['meldungen'] = array_values(array_filter($daten['meldungen'] ?? [], function ($m) use ($now) {
        return (int) $m['ts'] > $now - NB_PD_AUFBEWAHREN;
    }));
    $tag = nb_pd_tag($now);
    foreach ($daten['sperre'] ?? [] as $k => $s) {
        if (($s['tag'] ?? '') !== $tag) {
            unset($daten['sperre'][$k]);
        }
    }

    return $daten;
}

/** Oeffentliche Sicht auf die laufenden Meldungen, juengste zuerst. */
function nb_pd_oeffentlich(array $daten, $now)
{
    $orte = nb_pd_orte();
    $out = [];
    foreach ($daten['meldungen'] ?? [] as $m) {
        if (!nb_pd_sichtbar($m, $now)) {
            continue;
        }
        $out[] = [
            'id'       => $m['id'],
            'thema'    => $m['thema'],
            'bhf'      => $m['bhf'],
            'bhf_name' => $orte[$m['bhf']] ?? $m['bhf'],
            'zug'      => $m['zug'],
            'text'     => $m['text'],
            'zeit'     => nb_pd_iso($m['ts']),
            'bis'      => nb_pd_iso($m['bis']),
            'stimmt'   => count($m['stimmt'] ?? []),
        ];
    }
    usort($out, function ($a, $b) {
        return strcmp($b['zeit'], $a['zeit']);
    });

    return $out;
}

/** Liest die Daten, wendet $fn an und schreibt zurueck, unter Dateisperre. $fn gibt [daten, ergebnis]. */
function nb_pd_aendern(callable $fn)
{
    $dir = nb_pd_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    $h = @fopen(nb_pd_datei(), 'c+');
    if ($h === false) {
        return null;
    }
    flock($h, LOCK_EX);
    $alt = json_decode((string) stream_get_contents($h), true);
    $ergebnis = $fn(is_array($alt) ? $alt : []);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($ergebnis[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);

    return $ergebnis;
}

function nb_pd_lesen()
{
    return nordbahn_read_json(nb_pd_datei()) ?? [];
}

/** Discord-Nachricht zu einer neuen, wartenden oder gemeldeten Meldung. */
function nb_pd_discord_nachricht(array $m, $anlass, $siteUrl, $token = null)
{
    $meta = [
        'frei'     => ['titel' => 'Neue Pendlermeldung',                 'farbe' => 0x2a78d6],
        'wartet'   => ['titel' => 'Pendlermeldung wartet auf Freigabe',  'farbe' => 0xe0a100],
        'gemeldet' => ['titel' => 'Pendlermeldung als unangemessen gemeldet, ausgeblendet', 'farbe' => 0xd03b3b],
    ][$anlass];
    $orte = nb_pd_orte();
    $felder = [
        ['name' => 'Thema', 'value' => NB_PD_THEMEN[$m['thema']]['text'], 'inline' => true],
        ['name' => 'Bahnhof', 'value' => $orte[$m['bhf']] ?? $m['bhf'], 'inline' => true],
    ];
    if (!empty($m['zug'])) {
        $felder[] = ['name' => 'Zug', 'value' => (string) $m['zug'], 'inline' => true];
    }
    $sig = nb_pd_signatur($m['id'], $token);
    if ($sig !== '') {
        $felder[] = ['name' => 'Verwalten', 'value' => '[Freigeben oder entfernen](' . nb_pd_verwalten_url($m['id'], $siteUrl, $token) . ')', 'inline' => false];
    }

    return [
        'username'         => 'Bahn-Pinneberg.de Pendlermeldungen',
        'allowed_mentions' => ['parse' => []],
        'embeds'           => [[
            'title'       => $meta['titel'],
            'description' => $m['text'],
            'color'       => $meta['farbe'],
            'fields'      => $felder,
            'timestamp'   => date('c', (int) $m['ts']),
        ]],
    ];
}

if (PHP_SAPI === 'cli' || defined('NORDBAHN_PENDLER_LIB')) {
    return; // als Bibliothek eingebunden (Tests, Startseite)
}

/* ---------- Endpunkt ---------- */

function nb_pd_antwort($code, array $body)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Antwort schicken und die Verbindung schliessen, damit Discord den Besucher nicht aufhaelt. */
function nb_pd_antwort_und_weiter($code, array $body)
{
    ignore_user_abort(true);
    nb_pd_antwort($code, $body);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
}

/** Verwaltungsseite fuer den Betreiber (Link aus Discord). */
function nb_pd_verwalten($now)
{
    $id = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['id'] ?? $_GET['id'] ?? ''));
    $sig = (string) ($_POST['sig'] ?? $_GET['sig'] ?? '');
    $erwartet = nb_pd_signatur($id);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    if ($id === '' || $erwartet === '' || !hash_equals($erwartet, $sig)) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Kein Zugriff</title><p>Link ungültig.</p>';

        return;
    }
    $hinweis = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $entscheidung = ($_POST['entscheidung'] ?? '') === 'freigeben' ? 'freigeben' : 'entfernen';
        $r = nb_pd_aendern(function ($daten) use ($id, $entscheidung, $now) {
            return nb_pd_entscheiden($daten, $id, $entscheidung, $now);
        });
        $hinweis = $r === null ? 'Speichern ging nicht.' : ($r[1] === 'freigegeben' ? 'Freigegeben.' : ($r[1] === 'entfernt' ? 'Entfernt.' : 'Meldung nicht gefunden.'));
    }
    $daten = nb_pd_lesen();
    $i = nb_pd_finden($daten, $id);
    $m = $i === null ? null : $daten['meldungen'][$i];
    $orte = nb_pd_orte();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $status = ['frei' => 'sichtbar', 'wartet' => 'wartet auf Freigabe', 'gemeldet' => 'als unangemessen gemeldet, ausgeblendet', 'entfernt' => 'entfernt', 'geloescht' => 'vom Verfasser gelöscht'];
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Pendlermeldung verwalten</title>'
        . '<link rel="stylesheet" href="../../dashboard.css"></head><body><main class="wrap pd-verwalten"><h1>Pendlermeldung verwalten</h1>';
    if ($hinweis !== '') {
        echo '<p class="pd-verwalten__hinweis" role="status">' . $e($hinweis) . '</p>';
    }
    if ($m === null) {
        echo '<p>Diese Meldung gibt es nicht mehr (nach sieben Tagen gelöscht).</p></main></body></html>';

        return;
    }
    $laeuft = (int) $m['bis'] > $now;
    echo '<div class="card pd-verwalten__karte"><p><strong>' . $e(NB_PD_THEMEN[$m['thema']]['text']) . '</strong> · ' . $e($orte[$m['bhf']] ?? $m['bhf'])
        . ($m['zug'] ? ' · Zug ' . $e($m['zug']) : '') . '</p><blockquote>' . $e($m['text']) . '</blockquote>'
        . '<p>Geschrieben ' . $e(date('d.m. H:i', (int) $m['ts'])) . ', ' . ($laeuft ? 'läuft bis ' . $e(date('H:i', (int) $m['bis'])) : 'abgelaufen')
        . '. Status: ' . $e($status[$m['status']] ?? $m['status']) . '. ' . count($m['stimmt']) . '× bestätigt, ' . count($m['melden']) . '× unangemessen, ' . count($m['nein']) . '× stimmt nicht mehr.</p></div>'
        . '<form method="post" class="pd-verwalten__knoepfe"><input type="hidden" name="id" value="' . $e($id) . '"><input type="hidden" name="sig" value="' . $e($sig) . '">'
        . '<button type="submit" name="entscheidung" value="freigeben"' . ($m['status'] === 'frei' ? ' disabled' : '') . '>Freigeben</button> '
        . '<button type="submit" name="entscheidung" value="entfernen"' . ($m['status'] === 'entfernt' ? ' disabled' : '') . '>Entfernen</button></form>'
        . '</main></body></html>';
}

$now = time();
if (isset($_GET['verwalten'])) {
    nb_pd_verwalten($now);
    exit;
}
if (!nb_pd_an()) {
    nb_pd_antwort(200, ['ok' => true, 'an' => false, 'meldungen' => []]);
    exit;
}
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'GET' || $methode === 'HEAD') {
    nb_pd_antwort(200, ['ok' => true, 'an' => true, 'meldungen' => nb_pd_oeffentlich(nb_pd_lesen(), $now)]);
    exit;
}
if ($methode !== 'POST') {
    header('Allow: GET, POST');
    nb_pd_antwort(405, ['ok' => false, 'status' => 'nur_get_post']);
    exit;
}

$aktion = (string) ($_POST['aktion'] ?? '');
$kennung = nb_feedback_kennung($_SERVER, $now);
$ipKennung = nb_ip_kennung($_SERVER, $now);
$site = nordbahn_site_url();

if ($aktion !== 'schreiben') {
    if (!in_array($aktion, ['stimmt', 'stimmt_nicht', 'unangemessen', 'loeschen'], true)) {
        nb_pd_antwort(400, ['ok' => false, 'status' => 'ungueltig']);
        exit;
    }
    $basis = nb_feedback_pruefen(['nachricht' => 'Pendlermeldung'], $_SERVER, $site);
    if (in_array($basis['status'], ['origin', 'bot'], true)) {
        nb_pd_antwort($basis['code'], ['ok' => false, 'status' => $basis['status']]);
        exit;
    }
    if (!nb_feedback_limit($ipKennung, NORDBAHN_CACHE_DIR . '/pendler-klick', $now, NB_PD_LIMIT_KLICK, NB_PD_FENSTER)) {
        nb_pd_antwort(429, ['ok' => false, 'status' => 'limit']);
        exit;
    }
    $id = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['id'] ?? ''));
    $r = nb_pd_aendern(function ($daten) use ($id, $aktion, $kennung, $ipKennung, $now) {
        return nb_pd_aktion($daten, $id, $aktion, $kennung, $ipKennung, (string) ($_POST['geheim'] ?? ''), $now);
    });
    if ($r === null) {
        nb_pd_antwort(503, ['ok' => false, 'status' => 'speicher']);
        exit;
    }
    $ok = in_array($r[1], ['bestaetigt', 'notiert', 'beendet', 'gemeldet', 'geloescht'], true);
    $body = ['ok' => $ok, 'status' => $r[1], 'meldungen' => nb_pd_oeffentlich($r[0], $now)];
    if ($r[1] !== 'gemeldet' || ($webhook = nb_pd_discord_webhook()) === '') {
        nb_pd_antwort($ok ? 200 : 409, $body);
        exit;
    }
    nb_pd_antwort_und_weiter(200, $body);
    $i = nb_pd_finden($r[0], $id);
    nb_feedback_senden($webhook, nb_pd_discord_nachricht($r[0]['meldungen'][$i], 'gemeldet', $site));
    exit;
}

// Schreiben
$p = nb_pd_pruefen_schreiben($_POST, $_SERVER, $site, nb_cm_bekannte_zuege($now));
if ($p['status'] === 'honeypot') {
    nb_pd_antwort(200, ['ok' => true, 'status' => 'wartet', 'meldungen' => []]); // sieht fuer den Bot wie Erfolg aus
    exit;
}
if ($p['status'] === 'abgelehnt') {
    if (in_array($p['grund'], NB_PD_VERWARNEN, true)) {
        nb_pd_aendern(function ($daten) use ($kennung, $now) {
            return [nb_pd_verwarnen($daten, $kennung, $now), null];
        });
    }
    nb_pd_antwort(422, ['ok' => false, 'status' => 'abgelehnt', 'grund' => $p['grund']]);
    exit;
}
if ($p['code'] !== 200) {
    nb_pd_antwort($p['code'], ['ok' => false, 'status' => $p['status']]);
    exit;
}
// Limits vor allem anderen, damit niemand ueber Wiederholungen die KI-Pruefungen aufbraucht
if (!nb_feedback_limit($ipKennung, NORDBAHN_CACHE_DIR . '/pendler-limit-ip', $now, NB_PD_LIMIT_IP, NB_PD_FENSTER)
    || !nb_feedback_limit($kennung, NORDBAHN_CACHE_DIR . '/pendler-limit', $now, NB_PD_LIMIT, NB_PD_FENSTER)) {
    nb_pd_antwort(429, ['ok' => false, 'status' => 'limit']);
    exit;
}
// Vor der KI-Pruefung: Sperre, Doppel und Kostenbremse unter der Dateisperre klaeren
$kiUrl = nb_pd_pruefung_url();
$vorab = nb_pd_aendern(function ($daten) use ($p, $kennung, $kiUrl, $now) {
    $daten = nb_pd_aufraeumen($daten, $now);
    if (nb_pd_gesperrt($daten, $kennung, $now)) {
        return [$daten, 'gesperrt'];
    }
    if (nb_pd_doppelt($daten, $p['daten']['text'], $now)) {
        return [$daten, 'doppelt'];
    }
    if ($kiUrl === '') {
        return [$daten, 'nur_regeln'];
    }
    list($daten, $erlaubt) = nb_pd_ki_erlaubt($daten, $now);

    return [$daten, $erlaubt ? 'ki' : 'ohne_ki'];
});
if ($vorab === null) {
    nb_pd_antwort(503, ['ok' => false, 'status' => 'speicher']);
    exit;
}
if ($vorab[1] === 'gesperrt' || $vorab[1] === 'doppelt') {
    nb_pd_antwort($vorab[1] === 'gesperrt' ? 429 : 409, ['ok' => false, 'status' => $vorab[1]]);
    exit;
}
$urteil = $vorab[1] === 'ki' ? nb_pd_ki_pruefen($p['daten'], $kiUrl, nb_pd_token()) : null;
$status = nb_pd_start_status($vorab[1], $urteil);
$r = nb_pd_aendern(function ($daten) use ($p, $urteil, $status, $kennung, $ipKennung, $now) {
    if ($urteil !== null && !$urteil['ok']) {
        return [in_array($urteil['grund'], NB_PD_VERWARNEN, true) ? nb_pd_verwarnen($daten, $kennung, $now) : $daten, null];
    }
    list($daten, $id) = nb_pd_anlegen($daten, $p['daten'], $kennung, $ipKennung, $status, $now);

    return [$daten, $id];
});
if ($r === null) {
    nb_pd_antwort(503, ['ok' => false, 'status' => 'speicher']);
    exit;
}
if ($r[1] === null) {
    nb_pd_antwort(422, ['ok' => false, 'status' => 'abgelehnt', 'grund' => $urteil['grund']]);
    exit;
}
$i = nb_pd_finden($r[0], $r[1]);
$neu = $r[0]['meldungen'][$i];
nb_pd_antwort_und_weiter(200, ['ok' => true, 'status' => $neu['status'], 'id' => $neu['id'], 'meldungen' => nb_pd_oeffentlich($r[0], $now)]);
$webhook = nb_pd_discord_webhook();
if ($webhook !== '') {
    nb_feedback_senden($webhook, nb_pd_discord_nachricht($neu, $neu['status'], $site));
}
