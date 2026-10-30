<?php
/**
 * Feedback-Endpunkt: nimmt die Nachricht aus dem Feedback-Dialog (inc/footer.php,
 * feedback.js) entgegen und reicht sie an einen Discord-Webhook weiter
 * (NORDBAHN_FEEDBACK_WEBHOOK in config.php). Auf dem Server bleibt nichts
 * liegen, der Discord-Kanal ist der Posteingang.
 *
 * URL: /api/feedback (POST, Formulardaten)
 *   nachricht  Pflicht, 5 bis 2000 Zeichen
 *   art        fehler | idee | sonst
 *   kontakt    optional, bis 120 Zeichen
 *   seite      Pfad der Seite, von der die Nachricht kommt (nur der Pfad)
 *   website    Honeypot, bleibt bei Menschen leer
 * Antwort: {"ok":bool,"status":"gesendet|zu_kurz|limit|bot|origin|nicht_eingerichtet|webhook"}
 *
 * Ohne Konten ist das ein offenes Formular. Gegen Spam: nur POST vom eigenen
 * Origin, Bot-Filter auf den User-Agent, Honeypot, hoechstens NB_FEEDBACK_MAX
 * Nachrichten je Besucher und Stunde (Kennung aus IP, User-Agent und Tag, wie
 * Plausible nicht rueckrechenbar) und allowed_mentions leer, damit kein Text
 * den Discord-Server anpingen kann.
 */

require_once __DIR__ . '/store.php';

const NB_FEEDBACK_MIN      = 5;
const NB_FEEDBACK_MAX_TEXT = 2000;
const NB_FEEDBACK_MAX_KONTAKT = 120;
const NB_FEEDBACK_MAX_SEITE = 300;
const NB_FEEDBACK_MAX      = 5;      // Nachrichten je Besucher ...
const NB_FEEDBACK_FENSTER  = 3600;   // ... in diesem Zeitraum (Sekunden)

/** Steuerzeichen raus (ausser Zeilenumbruch und Tab), auf $max Zeichen gekuerzt. */
function nb_feedback_bereinigen($wert, $max)
{
    $wert = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $wert) ?? '';

    return trim(mb_substr(trim($wert), 0, $max));
}

/** Kennung des Besuchers fuer das Limit: IP, User-Agent und Tag, gekuerzter Hash, nichts davon wird gespeichert. */
function nb_feedback_kennung(array $server, $now = null)
{
    $ip = isset($server['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $server['HTTP_X_FORWARDED_FOR'])[0]) : ($server['REMOTE_ADDR'] ?? '');
    $tag = (new DateTime('@' . ($now ?? time())))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');

    return substr(hash('sha256', $ip . '|' . ($server['HTTP_USER_AGENT'] ?? '') . '|' . $tag), 0, 16);
}

/**
 * Prueft Herkunft und Eingaben. Ergebnis: ['code' => HTTP-Status, 'status' => Kurzwort,
 * 'daten' => bereinigte Felder (nur bei 200)]. Der Honeypot antwortet wie ein Erfolg,
 * aber mit 'honeypot' statt 'gesendet', damit der Aufrufer nichts verschickt.
 */
function nb_feedback_pruefen(array $post, array $server, $siteUrl)
{
    $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && strcasecmp(rtrim($origin, '/'), $siteUrl) !== 0 && parse_url($origin, PHP_URL_HOST) !== ($server['HTTP_HOST'] ?? null)) {
        return ['code' => 403, 'status' => 'origin'];
    }
    $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawler|spider|curl|wget|python|headless|scrapy|http-client/i', $ua)) {
        return ['code' => 403, 'status' => 'bot'];
    }
    if (trim((string) ($post['website'] ?? '')) !== '') {
        return ['code' => 200, 'status' => 'honeypot'];
    }
    $nachricht = nb_feedback_bereinigen($post['nachricht'] ?? '', NB_FEEDBACK_MAX_TEXT);
    if (mb_strlen($nachricht) < NB_FEEDBACK_MIN) {
        return ['code' => 400, 'status' => 'zu_kurz'];
    }
    $art = (string) ($post['art'] ?? 'sonst');
    if (!in_array($art, ['fehler', 'idee', 'sonst'], true)) {
        $art = 'sonst';
    }
    // Nur ein Pfad auf dieser Seite, keine fremde Adresse aus dem Browser
    $seite = nb_feedback_bereinigen($post['seite'] ?? '', NB_FEEDBACK_MAX_SEITE);
    if ($seite !== '' && !preg_match('#^/[^\s]*$#', $seite)) {
        $seite = '';
    }

    return ['code' => 200, 'status' => 'ok', 'daten' => [
        'nachricht' => $nachricht,
        'art'       => $art,
        'kontakt'   => nb_feedback_bereinigen($post['kontakt'] ?? '', NB_FEEDBACK_MAX_KONTAKT),
        'seite'     => $seite,
        'browser'   => mb_substr($ua, 0, 200),
    ]];
}

/**
 * Zaehlt die Nachricht gegen das Stundenlimit des Besuchers; false, wenn es
 * ausgeschoepft ist. Zeitstempel je Kennung liegen als JSON in $dir. Ohne
 * beschreibbares Verzeichnis gilt kein Limit, lieber eine Nachricht mehr als
 * ein kaputtes Formular fuer alle.
 */
function nb_feedback_limit($kennung, $dir, $now = null, $max = NB_FEEDBACK_MAX, $fenster = NB_FEEDBACK_FENSTER)
{
    $now = $now ?? time();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return true;
    }
    if (!is_writable($dir)) {
        return true;
    }
    $file = $dir . '/' . preg_replace('/[^a-f0-9]/', '', $kennung) . '.json';
    $stempel = [];
    if (is_file($file)) {
        $alt = json_decode((string) @file_get_contents($file), true);
        foreach (is_array($alt) ? $alt : [] as $t) {
            if ((int) $t > $now - $fenster) {
                $stempel[] = (int) $t;
            }
        }
    }
    if (count($stempel) >= $max) {
        return false;
    }
    $stempel[] = $now;
    @file_put_contents($file, json_encode($stempel), LOCK_EX);
    // Gelegentlich aufraeumen, damit nicht je Besucher eine Datei liegen bleibt
    if (mt_rand(1, 50) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < $now - $fenster) {
                @unlink($f);
            }
        }
    }

    return true;
}

/** Discord-Nachricht (Webhook-Payload) aus den geprueften Daten. */
function nb_feedback_nachricht(array $d, $siteUrl, $now = null)
{
    $meta = [
        'fehler' => ['titel' => 'Fehler gemeldet', 'farbe' => 0xd03b3b],
        'idee'   => ['titel' => 'Idee',            'farbe' => 0x2a78d6],
        'sonst'  => ['titel' => 'Feedback',        'farbe' => 0x0ca30c],
    ][$d['art']] ?? ['titel' => 'Feedback', 'farbe' => 0x0ca30c];
    $felder = [];
    if ($d['seite'] !== '') {
        $felder[] = ['name' => 'Seite', 'value' => '[' . $d['seite'] . '](' . $siteUrl . $d['seite'] . ')', 'inline' => true];
    }
    if ($d['kontakt'] !== '') {
        $felder[] = ['name' => 'Kontakt', 'value' => $d['kontakt'], 'inline' => true];
    }
    if ($d['browser'] !== '') {
        $felder[] = ['name' => 'Browser', 'value' => $d['browser'], 'inline' => false];
    }

    return [
        'username'         => 'Bahn-Pinneberg.de Feedback',
        // Nichts aus dem Formular darf jemanden auf dem Server anpingen
        'allowed_mentions' => ['parse' => []],
        'embeds'           => [[
            'title'       => $meta['titel'],
            'description' => $d['nachricht'],
            'color'       => $meta['farbe'],
            'fields'      => $felder,
            'timestamp'   => date('c', $now ?? time()),
        ]],
    ];
}

/** Schickt die Nachricht an den Webhook; true bei 2xx. */
function nb_feedback_senden($webhook, array $payload)
{
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $antwort = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);
    if ($antwort === false || $http < 200 || $http >= 300) {
        error_log('feedback: Webhook antwortet HTTP ' . $http . ($fehler !== '' ? ' (' . $fehler . ')' : ''));

        return false;
    }

    return true;
}

if (PHP_SAPI === 'cli' || defined('NORDBAHN_FEEDBACK_LIB')) {
    return; // als Bibliothek eingebunden (Tests, mcp.php fuer das Limit)
}

function nb_feedback_antwort($code, $status)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode(['ok' => $code === 200, 'status' => $status]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    nb_feedback_antwort(405, 'nur_post');
}
$webhook = nordbahn_feedback_webhook();
if ($webhook === '') {
    nb_feedback_antwort(503, 'nicht_eingerichtet');
}
$ergebnis = nb_feedback_pruefen($_POST, $_SERVER, nordbahn_site_url());
if ($ergebnis['status'] === 'honeypot') {
    nb_feedback_antwort(200, 'gesendet'); // sieht fuer den Bot wie Erfolg aus
}
if ($ergebnis['code'] !== 200) {
    nb_feedback_antwort($ergebnis['code'], $ergebnis['status']);
}
if (!nb_feedback_limit(nb_feedback_kennung($_SERVER), NORDBAHN_CACHE_DIR . '/feedback')) {
    nb_feedback_antwort(429, 'limit');
}
if (!nb_feedback_senden($webhook, nb_feedback_nachricht($ergebnis['daten'], nordbahn_site_url()))) {
    nb_feedback_antwort(502, 'webhook');
}
nb_feedback_antwort(200, 'gesendet');
