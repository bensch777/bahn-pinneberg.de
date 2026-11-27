<?php
/**
 * Fahrgastmeldungen: was Leute im Zug gerade erleben und die Echtzeitdaten nicht
 * oder zu spaet wissen (Zug steht, sehr voll, kurzer Zug, Klima oder Heizung aus).
 * Ein Tipp in der Tafel (aufgeklappte Fahrt) oder im Popup der Live-Karte, ohne
 * Konto und ohne Freitext.
 *
 * URL: /api/community
 *   GET   aktive Meldungen aller Zuege: {"ok":true,"an":true,"schwelle":1,
 *         "zuege":{"<Zugnummer>":[{"art":"steht","anzahl":1,"zuletzt":"ISO"}]}}
 *   POST  Formulardaten zug (Zugnummer), art (steht|voll|kurz|klima) und
 *         aktion (melden | zurueck | stimmt_nicht)
 *         Antwort {"ok":bool,"status":"gemeldet|zurueck|ausgeblendet|unbekannter_zug|
 *         limit|bot|origin|aus|ungueltig", "zuege":{...}} (zuege wie bei GET)
 *
 * Eine Meldung haengt an der Zugnummer, nicht am Bahnhof: wer in Elmshorn "voll"
 * meldet, wird in Pinneberg gesehen. Sie gilt fuer ihre Art eine feste Zeit nach
 * der letzten Meldung (NB_CM_ARTEN) und ist sichtbar ab NORDBAHN_COMMUNITY_SCHWELLE
 * verschiedenen Besuchern (Vorgabe 1). "Stimmt nicht mehr" verwirft alle bis dahin
 * eingegangenen Meldungen dieser Art fuer den Zug.
 *
 * Gegen Missbrauch: nur feste Arten, keine Texte; nur Zuege, die gerade fahren oder
 * gleich abfahren (bekannt aus der Live-Karte oder einer frischen Tafel); je
 * Besucher eine Meldung pro Art und Zug; hoechstens NB_CM_LIMIT Aktionen je
 * Besucher und NB_CM_LIMIT_IP je IP-Adresse und Stunde (sonst hilft ein neuer
 * User-Agent je Anfrage, um jedes Limit zu umgehen); Herkunft und Bot-Filter wie beim Feedback. Besucher sind
 * nur als Tageskennung bekannt (Hash aus IP, Browser und Tag, nb_feedback_kennung).
 * Gespeichert wird je Tag eine Datei cache/community/<Tag>.json, aeltere Tage
 * werden geloescht. Aus mit NORDBAHN_COMMUNITY => false in config.php.
 */

require_once __DIR__ . '/../inc/store.php';
if (!function_exists('nb_feedback_kennung')) {
    if (!defined('NORDBAHN_FEEDBACK_LIB')) {
        define('NORDBAHN_FEEDBACK_LIB', true);
    }
    require_once __DIR__ . '/feedback.php';
}

/** Arten mit Gueltigkeit in Minuten nach der letzten Meldung. */
const NB_CM_ARTEN = [
    'steht' => 15,
    'voll'  => 30,
    'kurz'  => 120,
    'klima' => 120,
];
const NB_CM_LIMIT    = 20;   // Aktionen je Besucher ...
const NB_CM_FENSTER  = 3600; // ... in diesem Zeitraum (Sekunden)
const NB_CM_LIMIT_IP = 40;   // Aktionen je IP-Adresse in diesem Zeitraum, egal mit welchem Browser
const NB_CM_FRISCH   = 600;  // so alt darf ein Stand sein, der einen Zug als "unterwegs" belegt
const NB_CM_VORLAUF  = 15;   // Minuten vor der Abfahrt, ab denen gemeldet werden darf

function nb_cm_dir()
{
    return NORDBAHN_CACHE_DIR . '/community';
}

/** Ist die Funktion eingeschaltet? Vorgabe an. */
function nb_cm_an()
{
    $config = nordbahn_config();

    return !array_key_exists('NORDBAHN_COMMUNITY', $config) || !empty($config['NORDBAHN_COMMUNITY']);
}

/** Ab wie vielen verschiedenen Besuchern eine Meldung sichtbar ist (1 bis 5, Vorgabe 1). */
function nb_cm_schwelle()
{
    $config = nordbahn_config();
    $n = (int) ($config['NORDBAHN_COMMUNITY_SCHWELLE'] ?? 1);

    return max(1, min(5, $n));
}

function nb_cm_tag($now)
{
    return (new DateTime('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
}

function nb_cm_datei($now)
{
    return nb_cm_dir() . '/' . nb_cm_tag($now) . '.json';
}

/**
 * Zugnummern, fuer die gerade gemeldet werden darf: Zuege der Live-Karte (unterwegs
 * oder in den naechsten 20 Minuten irgendwo ab) und Zeilen frischer Tafeln, die laut
 * Plan in hoechstens NB_CM_VORLAUF Minuten abfahren oder schon weg sind.
 */
function nb_cm_bekannte_zuege($now, $dir = null)
{
    $dir = $dir ?? NORDBAHN_CACHE_DIR;
    $zuege = [];
    $frisch = function ($d) use ($now) {
        return is_array($d) && isset($d['fetched_at']) && $now - strtotime($d['fetched_at']) <= NB_CM_FRISCH;
    };
    $karte = nordbahn_read_json($dir . '/karte.json');
    if ($frisch($karte)) {
        foreach ($karte['zuege'] ?? [] as $z) {
            if (!empty($z['zug'])) {
                $zuege[(string) $z['zug']] = true;
            }
        }
    }
    foreach (glob($dir . '/abfahrten-*.json') ?: [] as $f) {
        $tafel = nordbahn_read_json($f);
        if (!$frisch($tafel)) {
            continue;
        }
        foreach ($tafel['richtungen'] ?? [] as $zeilen) {
            foreach ((array) $zeilen as $z) {
                if (empty($z['zug']) || !empty($z['ausfall'])) {
                    continue;
                }
                // Planzeit: wer bei Verspaetung schon am Bahnsteig steht, soll melden koennen
                $t = strtotime((string) ($z['zeit'] ?? ''));
                if ($t !== false && $t - $now <= NB_CM_VORLAUF * 60) {
                    $zuege[(string) $z['zug']] = true;
                }
            }
        }
    }

    return $zuege;
}

/**
 * Aktive, sichtbare Meldungen aus den gespeicherten Daten:
 * ['<zug>' => [['art', 'anzahl', 'zuletzt'], ...]], je Zug in der Reihenfolge von NB_CM_ARTEN.
 */
function nb_cm_aktiv(array $daten, $now, $schwelle = 1)
{
    $out = [];
    foreach ($daten as $zug => $arten) {
        foreach (NB_CM_ARTEN as $art => $minuten) {
            $meldungen = $arten[$art] ?? [];
            $gueltig = array_filter((array) $meldungen, function ($t) use ($now, $minuten) {
                return (int) $t > $now - $minuten * 60 && (int) $t <= $now + 60;
            });
            if (count($gueltig) < $schwelle || !$gueltig) {
                continue;
            }
            $out[(string) $zug][] = [
                'art'     => $art,
                'anzahl'  => count($gueltig),
                'zuletzt' => (new DateTime('@' . max($gueltig)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('c'),
            ];
        }
    }

    return $out;
}

/**
 * Wendet eine Aktion auf die Tagesdaten an. $daten: [zug => [art => [kennung => zeit]]].
 * Gibt [daten, status] zurueck.
 */
function nb_cm_anwenden(array $daten, $zug, $art, $aktion, $kennung, $now)
{
    if ($aktion === 'melden') {
        $daten[$zug][$art][$kennung] = $now;

        return [$daten, 'gemeldet'];
    }
    if ($aktion === 'zurueck') {
        unset($daten[$zug][$art][$kennung]);

        return [nb_cm_aufraeumen($daten, $zug, $art), 'zurueck'];
    }
    // stimmt_nicht: alles bis jetzt Gemeldete dieser Art verwerfen
    unset($daten[$zug][$art]);

    return [nb_cm_aufraeumen($daten, $zug, $art), 'ausgeblendet'];
}

function nb_cm_aufraeumen(array $daten, $zug, $art)
{
    if (isset($daten[$zug][$art]) && !$daten[$zug][$art]) {
        unset($daten[$zug][$art]);
    }
    if (isset($daten[$zug]) && !$daten[$zug]) {
        unset($daten[$zug]);
    }

    return $daten;
}

/** Prueft eine POST-Anfrage. Ergebnis: ['code', 'status', 'zug', 'art', 'aktion']. */
function nb_cm_pruefen(array $post, array $server, $siteUrl, array $bekannt)
{
    $basis = nb_feedback_pruefen(['nachricht' => 'Fahrgastmeldung'] + array_intersect_key($post, ['website' => 1]), $server, $siteUrl);
    if (in_array($basis['status'], ['origin', 'bot', 'honeypot'], true)) {
        return ['code' => $basis['code'], 'status' => $basis['status']];
    }
    $zug = preg_replace('/\D/', '', (string) ($post['zug'] ?? ''));
    $art = (string) ($post['art'] ?? '');
    $aktion = (string) ($post['aktion'] ?? 'melden');
    if ($zug === '' || strlen($zug) > 6 || !isset(NB_CM_ARTEN[$art]) || !in_array($aktion, ['melden', 'zurueck', 'stimmt_nicht'], true)) {
        return ['code' => 400, 'status' => 'ungueltig'];
    }
    // Zuruecknehmen geht immer, Melden und Ausblenden nur fuer Zuege, die gerade fahren
    if ($aktion !== 'zurueck' && !isset($bekannt[$zug])) {
        return ['code' => 409, 'status' => 'unbekannter_zug'];
    }

    return ['code' => 200, 'status' => 'ok', 'zug' => $zug, 'art' => $art, 'aktion' => $aktion];
}

/** Liest die Tagesdaten, wendet $fn an und schreibt zurueck, unter Dateisperre. */
function nb_cm_aendern($now, callable $fn)
{
    $dir = nb_cm_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    $file = nb_cm_datei($now);
    $h = @fopen($file, 'c+');
    if ($h === false) {
        return null;
    }
    flock($h, LOCK_EX);
    $alt = json_decode((string) stream_get_contents($h), true);
    $daten = is_array($alt) ? $alt : [];
    $ergebnis = $fn($daten);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($ergebnis[0], JSON_UNESCAPED_SLASHES));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    // Vortage weg: die Kennungen gelten ohnehin nur einen Tag
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if ($f !== $file) {
            @unlink($f);
        }
    }

    return $ergebnis;
}

/** Sichtbare Meldungen von heute. */
function nb_cm_lesen($now)
{
    $daten = nordbahn_read_json(nb_cm_datei($now));

    return nb_cm_aktiv(is_array($daten) ? $daten : [], $now, nb_cm_schwelle());
}

if (PHP_SAPI === 'cli' || defined('NORDBAHN_COMMUNITY_LIB')) {
    return; // als Bibliothek eingebunden (Tests)
}

function nb_cm_antwort($code, array $body)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$now = time();
if (!nb_cm_an()) {
    nb_cm_antwort(200, ['ok' => true, 'an' => false, 'zuege' => new stdClass()]);
}
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'GET' || $methode === 'HEAD') {
    $aktiv = nb_cm_lesen($now);
    nb_cm_antwort(200, ['ok' => true, 'an' => true, 'schwelle' => nb_cm_schwelle(), 'zuege' => $aktiv ?: new stdClass()]);
}
if ($methode !== 'POST') {
    header('Allow: GET, POST');
    nb_cm_antwort(405, ['ok' => false, 'status' => 'nur_get_post']);
}
$p = nb_cm_pruefen($_POST, $_SERVER, nordbahn_site_url(), nb_cm_bekannte_zuege($now));
if ($p['status'] === 'honeypot') {
    nb_cm_antwort(200, ['ok' => true, 'status' => 'gemeldet', 'zuege' => new stdClass()]); // sieht fuer den Bot wie Erfolg aus
}
if ($p['code'] !== 200) {
    nb_cm_antwort($p['code'], ['ok' => false, 'status' => $p['status']]);
}
$kennung = nb_feedback_kennung($_SERVER, $now);
if (!nb_feedback_limit(nb_ip_kennung($_SERVER, $now), NORDBAHN_CACHE_DIR . '/community-limit-ip', $now, NB_CM_LIMIT_IP, NB_CM_FENSTER)
    || !nb_feedback_limit($kennung, NORDBAHN_CACHE_DIR . '/community-limit', $now, NB_CM_LIMIT, NB_CM_FENSTER)) {
    nb_cm_antwort(429, ['ok' => false, 'status' => 'limit']);
}
$ergebnis = nb_cm_aendern($now, function ($daten) use ($p, $kennung, $now) {
    return nb_cm_anwenden($daten, $p['zug'], $p['art'], $p['aktion'], $kennung, $now);
});
if ($ergebnis === null) {
    nb_cm_antwort(503, ['ok' => false, 'status' => 'speicher']);
}
$aktiv = nb_cm_aktiv($ergebnis[0], $now, nb_cm_schwelle());
nb_cm_antwort(200, ['ok' => true, 'status' => $ergebnis[1], 'zuege' => $aktiv ?: new stdClass()]);
