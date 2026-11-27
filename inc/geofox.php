<?php
/**
 * HVV-Schnittstelle Geofox GTI: S-Bahn S3 zwischen Pinneberg und Hamburg sowie
 * HVV-Meldungen zu S3, RB60, RB61 und RB71. Reine Bibliothek, gibt nichts aus.
 *
 * Zugang (Nutzer, Passwort) steht in config.php; ohne Zugang ist alles aus
 * (gf_aktiv() false, Karte und Abschnitt auf der Startseite fehlen).
 *
 * Je Aktualisierung drei Aufrufe: departureList ab Pinneberg und ab Hamburg Hbf,
 * getAnnouncements. Ergebnis liegt hoechstens GF_TTL Sekunden in cache/geofox.json,
 * danach liefert api/s3 bis GF_STALE_TTL den alten Stand sofort und erneuert im
 * Hintergrund (wie die Abfahrten). Stations-IDs kommen einmal am Tag per checkName
 * und liegen in cache/geofox-stationen.json.
 *
 * Mit echten Antworten zu pruefen (die Entwicklung lief ohne Zugriff auf die API):
 * Feldnamen und Einheiten in gf_clean_abfahrt (timeOffset in Minuten ab der
 * Anfragezeit, delay in Sekunden), die Struktur der Meldungen in gf_clean_meldung
 * (Linien unter lines[] oder locations[].line), die Protokollversion (GF_VERSION)
 * und die Antwort von checkName. Rohdaten: dev/geofox.php (CLI) oder im Browser
 * api/s3?roh=<NORDBAHN_PUSH_TOKEN> (gf_diagnose).
 */

require_once __DIR__ . '/store.php';

const GF_API_DEFAULT  = 'https://gti.geofox.de/gti/public';
const GF_VERSION      = 59;        // Protokollversion, per Konfiguration ueberschreibbar
const GF_TTL          = 60;        // so lange gilt ein Stand als frisch
const GF_STALE_TTL    = 180;       // bis dahin wird ein alter Stand sofort geliefert
const GF_TIMEOUT      = 6;
const GF_FENSTER_MIN  = 60;        // Abfahrten der naechsten 60 Minuten
const GF_PER_DIR      = 6;         // je Richtung fuer Statusregel und Anzeige
const GF_MAX_LIST     = 150;       // Zeilen je ungefilterter departureList-Anfrage (am Hbf ~100 Abfahrten je Stunde)
const GF_S3_SERVICE_ID = 'SBH:S3_SBH_SBAHNS'; // Linien-ID der S3 in der GTI (aus echten Antworten), fuer den Filter
// Meldungen, die Pendler auf der Strecke nicht brauchen: Aufzuege, Rolltreppen, Barrierefreiheit
const GF_MELDUNG_AUSSCHLUSS = '/aufzug|aufzüge|fahrstuhl|rolltreppe|\\blift\\b|barrierefrei|fahrtreppe|elevator|escalator|erreichbarkeit der station/iu';
// Der HVV haengt Meldungen oft an alle Linien, die im betroffenen Abschnitt halten: eine
// Meldung "Verspaetungen und Ausfaelle auf der S1" traegt dann auch die S3. Darum zaehlt
// die S3 an einer Meldung nur, wenn der Titel sie nennt ("S3", "S 3", "S3/S5"; nicht "S31").
// Bei RB60, RB61 und RB71 nennt der Titel die Linie fast nie, dort gilt die Zuordnung.
const GF_S3_IM_TITEL = '/\bS\s?3\b/u';
// S3-Stationen zwischen Pinneberg und der Innenstadt. Eine S3-Meldung zaehlt nur, wenn
// ihr Abschnitt hier beginnt oder endet; Meldungen zu Stade, Buxtehude oder Neugraben
// betreffen Pendler aus Pinneberg nicht. RB60, RB61 und RB71 zaehlen immer.
const GF_S3_KORRIDOR = [
    'pinneberg', 'thesdorf', 'halstenbek', 'krupunder', 'elbgaustrasse', 'eidelstedt', 'stellingen',
    'langenfelde', 'diebsteich', 'holstenstrasse', 'bahrenfeld', 'ottensen', 'altona', 'koenigstrasse',
    'reeperbahn', 'landungsbruecken', 'stadthausbruecke', 'jungfernstieg', 'sternschanze', 'dammtor', 'hauptbahnhof',
];
const GF_DELAY_MIN    = 5;         // ab dieser Verspaetung (Minuten) zaehlt ein Zug als betroffen
const GF_WARN_ANTEIL  = 0.35;      // ab diesem Anteil betroffener Zuege "S3 beeintraechtigt"
const GF_ALERT_ANTEIL = 0.6;       // ab diesem Anteil "S3 gestoert"
const GF_WARN_AUSFAELLE = 2;       // ab so vielen Ausfaellen mindestens "beeintraechtigt", auch unter GF_WARN_ANTEIL
const GF_WARN_DELAY   = 15;        // eine Verspaetung ab so vielen Minuten macht mindestens "beeintraechtigt"
const GF_LINIE        = 'S3';
const GF_LINIEN       = ['S3', 'RB60', 'RB61', 'RB71'];
const GF_MELDUNGEN_MAX = 20;
const GF_STATIONEN_TTL = 86400;    // Stations-IDs einmal am Tag auffrischen
const GF_PINNEBERG = 'Pinneberg';
// Stadtbahnhoefe fuer die Rueckrichtung (?stadt=), waehlbar auf der Startseite
// in Fahrtrichtung ab Pinneberg
const GF_STADT = [
    'altona'           => ['name' => 'Hamburg-Altona',  'kurz' => 'Altona'],
    'stadthausbruecke' => ['name' => 'Stadthausbrücke', 'kurz' => 'Stadthausbrücke'],
    'jungfernstieg'    => ['name' => 'Jungfernstieg',   'kurz' => 'Jungfernstieg'],
    'hbf'              => ['name' => 'Hamburg Hbf',     'kurz' => 'Hbf'],
];
const GF_STADT_DEFAULT = 'hbf';
define('GF_CACHE', NORDBAHN_CACHE_DIR . '/geofox-' . GF_STADT_DEFAULT . '.json');
define('GF_MELDUNGEN_CACHE', NORDBAHN_CACHE_DIR . '/geofox-meldungen.json');
const GF_S3_KURS_MAX = 12;       // Laufweg-Abfragen (departureCourse) je Aufbereitung fuer die Ankunftszeit
define('GF_S3_KURS_CACHE', NORDBAHN_CACHE_DIR . '/geofox-s3-kurse.json');
define('GF_STATIONEN_CACHE', NORDBAHN_CACHE_DIR . '/geofox-stationen.json');

/** Cache-Datei je Stadtbahnhof; GF_CACHE ist die des Standards. */
function gf_cache_file($stadt = GF_STADT_DEFAULT, $endung = 'json')
{
    return NORDBAHN_CACHE_DIR . '/geofox-' . (isset(GF_STADT[$stadt]) ? $stadt : GF_STADT_DEFAULT) . '.' . $endung;
}

/** Beide Richtungen fuer einen Stadtbahnhof: hin ab Pinneberg (alles Richtung Hamburg), rueck ab Stadt nur nach Pinneberg. */
function gf_richtungen($stadt = GF_STADT_DEFAULT)
{
    $st = GF_STADT[$stadt] ?? GF_STADT[GF_STADT_DEFAULT];

    return [
        'hin'   => ['name' => GF_PINNEBERG, 'ziel' => '', 'ab' => GF_PINNEBERG],
        'rueck' => ['name' => $st['name'], 'ziel' => GF_PINNEBERG, 'ab' => $st['name']],
    ];
}

/* ---------- Zugang und Anfrage ---------- */

/** Zugangsdaten aus der Konfiguration (oder aus $config fuer Tests), null ohne Nutzer und Passwort. */
function gf_zugang(?array $config = null)
{
    $c = $config ?? nordbahn_config();
    $user = trim((string) ($c['NORDBAHN_GEOFOX_USER'] ?? ''));
    $pass = (string) ($c['NORDBAHN_GEOFOX_PASSWORD'] ?? '');
    if ($user === '' || $pass === '') {
        return null;
    }
    $url = trim((string) ($c['NORDBAHN_GEOFOX_URL'] ?? ''));
    $version = (int) ($c['NORDBAHN_GEOFOX_VERSION'] ?? 0);

    return [
        'user'     => $user,
        'password' => $pass,
        'url'      => rtrim($url !== '' ? $url : GF_API_DEFAULT, '/'),
        'version'  => $version > 0 ? $version : GF_VERSION,
    ];
}

function gf_aktiv()
{
    return gf_zugang() !== null;
}

/** Signatur der GTI: Base64 des rohen HMAC-SHA1 ueber den exakten Anfragetext. */
function gf_signature($body, $password)
{
    return base64_encode(hash_hmac('sha1', $body, $password, true));
}

function gf_headers($body, array $zugang)
{
    return [
        'Content-Type: application/json;charset=UTF-8',
        'Accept: application/json',
        'geofox-auth-type: HmacSHA1',
        'geofox-auth-user: ' . $zugang['user'],
        'geofox-auth-signature: ' . gf_signature($body, $zugang['password']),
        'X-Platform: web',
    ];
}

/** POST an die GTI: ['status' => HTTP-Status (0 ohne Verbindung), 'body' => Antworttext, 'retry_after' => Sekunden oder null]. */
function gf_fetch_roh($method, $body, array $zugang)
{
    $url = $zugang['url'] . '/' . $method;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => gf_headers($body, $zugang),
            CURLOPT_CONNECTTIMEOUT => GF_TIMEOUT,
            CURLOPT_TIMEOUT        => GF_TIMEOUT,
            CURLOPT_USERAGENT      => nordbahn_site_host() . ' Monitor',
            CURLOPT_HEADERFUNCTION => function ($ch, $zeile) use (&$retryAfter) {
                if (preg_match('/^Retry-After:\s*(\d+)/i', $zeile, $m)) {
                    $retryAfter = (int) $m[1];
                }

                return strlen($zeile);
            },
        ]);
        $retryAfter = null;
        $antwort = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $antwort === false ? '' : (string) $antwort, 'retry_after' => $retryAfter];
    }
    $antwort = @file_get_contents($url, false, stream_context_create([
        'http' => ['method' => 'POST', 'timeout' => GF_TIMEOUT, 'user_agent' => nordbahn_site_host() . ' Monitor', 'header' => implode("\r\n", gf_headers($body, $zugang)) . "\r\n", 'content' => $body, 'ignore_errors' => true],
    ]));
    $status = 0;
    $retryAfter = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('/^Retry-After:\s*(\d+)/i', $h, $m)) {
            $retryAfter = (int) $m[1];
        }
    }

    return ['status' => $status, 'body' => $antwort === false ? '' : (string) $antwort, 'retry_after' => $retryAfter];
}

/**
 * Pause nach Fehlern der GTI, geteilt fuer alle Prozesse: Solange sie laeuft, geht
 * keine Anfrage raus. Ohne Pause wuerde jeder Seitenaufruf neu bauen und die
 * Schnittstelle gerade dann am staerksten belasten, wenn sie drosselt (429) oder
 * den Zugang ablehnt (401/403). Die Diagnose (gf_diagnose) fragt trotzdem.
 */
define('GF_PAUSE_FILE', NORDBAHN_CACHE_DIR . '/geofox-pause.json');
const GF_PAUSE_NETZ   = 60;    // Sekunden nach Timeout, Verbindungsfehler oder 5xx
const GF_PAUSE_LIMIT  = 300;   // nach 429 ohne Retry-After
const GF_PAUSE_ZUGANG = 900;   // nach 401/403, meist hilft nur eine neue config.php
const GF_PAUSE_MAX    = 3600;

/** Laeuft gerade eine Pause? Dann Sekunden bis zum Ende, sonst 0. */
function gf_pause_rest($now = null)
{
    $p = nordbahn_read_json(GF_PAUSE_FILE);
    $rest = is_array($p) ? (int) ($p['bis'] ?? 0) - ($now ?? time()) : 0;

    return max(0, $rest);
}

/**
 * Pausendauer fuer eine Antwort der GTI, 0 wenn alles gut ist. $retryAfter ist der
 * Header Retry-After in Sekunden (oder null).
 */
function gf_pause_dauer($status, $retryAfter = null)
{
    $status = (int) $status;
    if ($status === 429) {
        $s = $retryAfter !== null && $retryAfter > 0 ? (int) $retryAfter : GF_PAUSE_LIMIT;

        return min($s, GF_PAUSE_MAX);
    }
    if ($status === 401 || $status === 403) {
        return GF_PAUSE_ZUGANG;
    }
    if ($status === 0 || $status >= 500) {
        return GF_PAUSE_NETZ;
    }

    return 0;
}

/** Pause beginnen und einmal ins Fehlerlog schreiben. */
function gf_pause_setzen($status, $sekunden, $method, $now = null)
{
    $now = $now ?? time();
    nordbahn_write_json(GF_PAUSE_FILE, ['bis' => $now + (int) $sekunden, 'status' => (int) $status, 'methode' => (string) $method, 'seit' => gmdate('Y-m-d\TH:i:s\Z', $now)]);
    error_log(sprintf('Geofox: HTTP %d bei %s, %d s Pause', (int) $status, $method, (int) $sekunden));
}

/**
 * Zaehlt jede echte Anfrage an die GTI je Stunde und Methode, dazu Fehler (alles
 * ausser HTTP 200), in cache/geofox-zaehler.json fuer die letzten GF_ZAEHLER_TAGE
 * Tage. So laesst sich das Volumen mit dem Limit des Zugangs vergleichen; die
 * Diagnose (api/s3?roh=) zeigt die Zahlen.
 */
define('GF_ZAEHLER_FILE', NORDBAHN_CACHE_DIR . '/geofox-zaehler.json');
const GF_ZAEHLER_TAGE = 8;

function gf_zaehlen($method, $status, $now = null)
{
    $dt = new DateTime('@' . ($now ?? time()));
    $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
    $tag = $dt->format('Y-m-d');
    $stunde = $dt->format('H');
    $lock = @fopen(GF_ZAEHLER_FILE . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return;
    }
    $z = nordbahn_read_json(GF_ZAEHLER_FILE) ?? [];
    $z[$tag][$stunde][$method] = ($z[$tag][$stunde][$method] ?? 0) + 1;
    if ((int) $status !== 200) {
        $z[$tag][$stunde]['fehler'] = ($z[$tag][$stunde]['fehler'] ?? 0) + 1;
    }
    ksort($z);
    $z = array_slice($z, -GF_ZAEHLER_TAGE, null, true);
    nordbahn_write_json(GF_ZAEHLER_FILE, $z);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** Zaehler zusammengefasst: je Tag gesamt, Fehler, je Methode und die staerkste Stunde; dazu Pause und Wissen ueber den Linienfilter. */
function gf_zaehler_uebersicht()
{
    $z = nordbahn_read_json(GF_ZAEHLER_FILE) ?? [];
    $tage = [];
    foreach ($z as $tag => $stunden) {
        $summe = ['gesamt' => 0, 'fehler' => 0, 'methoden' => [], 'max_stunde' => 0];
        foreach ($stunden as $werte) {
            $n = 0;
            foreach ($werte as $m => $c) {
                if ($m === 'fehler') {
                    $summe['fehler'] += $c;
                    continue;
                }
                $summe['methoden'][$m] = ($summe['methoden'][$m] ?? 0) + $c;
                $n += $c;
            }
            $summe['gesamt'] += $n;
            $summe['max_stunde'] = max($summe['max_stunde'], $n);
        }
        arsort($summe['methoden']);
        $tage[$tag] = $summe;
    }
    krsort($tage);

    return ['tage' => $tage, 'pause' => nordbahn_read_json(GF_PAUSE_FILE), 'linienfilter' => nordbahn_read_json(GF_REGIO_FILTER_CACHE)];
}

/** Antworttext bei HTTP 200, sonst null. Waehrend einer Pause ohne Anfrage null. */
function gf_fetch($method, $body, array $zugang)
{
    if (gf_pause_rest() > 0) {
        return null;
    }
    $r = gf_fetch_roh($method, $body, $zugang);
    gf_zaehlen($method, $r['status']);
    $pause = gf_pause_dauer($r['status'], $r['retry_after'] ?? null);
    if ($pause > 0) {
        gf_pause_setzen($r['status'], $pause, $method);
    }

    return $r['status'] === 200 && $r['body'] !== '' ? $r['body'] : null;
}

/**
 * Eine Methode der GTI aufrufen. $fetch ist function ($method, $json) (Tests,
 * CLI), sonst gf_fetch mit dem Zugang aus der Konfiguration. Signatur und Body
 * sind derselbe String. Null bei Fehler oder returnCode != OK.
 */
function gf_request($method, array $body, $fetch = null, ?array $zugang = null)
{
    $zugang = $zugang ?? gf_zugang();
    if ($fetch === null) {
        if ($zugang === null) {
            return null;
        }
        $fetch = function ($method, $json) use ($zugang) {
            return gf_fetch($method, $json, $zugang);
        };
    }
    $body = ['version' => $zugang['version'] ?? GF_VERSION, 'language' => 'de'] + $body;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $antwort = $fetch($method, $json);
    if (!is_string($antwort) || $antwort === '') {
        return null;
    }
    $daten = json_decode($antwort, true);
    if (!is_array($daten) || ($daten['returnCode'] ?? '') !== 'OK') {
        return null;
    }

    return $daten;
}

/** GTITime fuer eine Anfrage: Datum und Uhrzeit in deutscher Zeit. */
function gf_zeit_param(DateTime $now)
{
    $d = (clone $now)->setTimezone(new DateTimeZone('Europe/Berlin'));

    return ['date' => $d->format('d.m.Y'), 'time' => $d->format('H:i')];
}

/** GTITime (date dd.mm.yyyy, time HH:MM, deutsche Zeit) als DateTime, null wenn unbrauchbar. */
function gf_zeit_lesen($gti, ?DateTime $fallback = null)
{
    if (is_array($gti) && !empty($gti['date']) && !empty($gti['time'])) {
        $d = DateTime::createFromFormat('d.m.Y H:i', $gti['date'] . ' ' . $gti['time'], new DateTimeZone('Europe/Berlin'));
        if ($d instanceof DateTime) {
            return $d;
        }
    }

    return $fallback;
}

/* ---------- Stationen ---------- */

/** Station per checkName aufloesen: ['name','id','type'] oder null. */
function gf_station_suchen($fetch, $name, ?array $zugang = null)
{
    $antwort = gf_request('checkName', ['theName' => ['name' => $name, 'type' => 'STATION'], 'maxList' => 5], $fetch, $zugang);
    foreach ($antwort['results'] ?? [] as $r) {
        if (is_array($r) && ($r['type'] ?? '') === 'STATION' && !empty($r['id'])) {
            return ['name' => nordbahn_text($r['name'] ?? $name, 80), 'id' => nordbahn_text($r['id'], 80), 'type' => 'STATION'];
        }
    }

    return null;
}

/**
 * Station nach Name, ID aus dem Tagescache oder frisch per checkName. Ohne ID geht
 * die Anfrage mit Name und Typ raus (die GTI loest Namen selbst auf).
 */
function gf_station($fetch, $name, $now = null, ?array $zugang = null)
{
    $now = $now ?? time();
    $cache = nordbahn_read_json(GF_STATIONEN_CACHE);
    $stationen = is_array($cache) && isset($cache['stand']) && $now - (int) $cache['stand'] < GF_STATIONEN_TTL && is_array($cache['stationen'] ?? null) ? $cache['stationen'] : [];
    if (!empty($stationen[$name]['id'])) {
        return $stationen[$name];
    }
    $station = gf_station_suchen($fetch, $name, $zugang);
    if ($station !== null) {
        $stationen[$name] = $station;
        nordbahn_write_json(GF_STATIONEN_CACHE, ['stand' => $stationen === [$name => $station] ? $now : (int) ($cache['stand'] ?? $now), 'stationen' => $stationen]);

        return $station;
    }

    return ['name' => $name, 'type' => 'STATION'];
}

/* ---------- Abfahrten ---------- */

/**
 * Eine Abfahrt der GTI in unser Format: zeit (Plan, ISO), ist (mit Verspaetung),
 * linie, ziel, ab, delay_min, ausfall, gleis, extra. Null ohne brauchbare Linie.
 * Annahme: timeOffset in Minuten ab $basis, delay in Sekunden.
 */
function gf_clean_abfahrt($raw, DateTime $basis, $ab)
{
    if (!is_array($raw) || !is_array($raw['line'] ?? null)) {
        return null;
    }
    $linie = str_replace(' ', '', nordbahn_text($raw['line']['name'] ?? '', 20));
    if ($linie === '') {
        return null;
    }
    $plan = (clone $basis)->modify('+' . (int) ($raw['timeOffset'] ?? 0) . ' minutes');
    $delay = (int) round(((int) ($raw['delay'] ?? 0)) / 60);
    $ist = (clone $plan)->modify(($delay >= 0 ? '+' : '') . $delay . ' minutes');
    // "Gleis 1 (S-Bahn)" am Hbf wird "1": Zusatz in Klammern und das Wort Gleis weg
    $gleis = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', nordbahn_text($raw['realtimePlatform'] ?? ($raw['platform'] ?? ''), 30)));

    return [
        'zeit'      => $plan->format('c'),
        'ist'       => $ist->format('c'),
        'linie'     => $linie,
        'ziel'      => nordbahn_text($raw['line']['direction'] ?? '', 80),
        'ab'        => $ab,
        'delay_min' => $delay,
        'ausfall'   => !empty($raw['cancelled']),
        'gleis'     => preg_replace('/^gleis\s*/i', '', $gleis),
        'extra'     => !empty($raw['extra']),
    ];
}

/** S3-Zeilen einer departureList-Antwort fuer eine Richtung (Linie und ggf. Ziel gefiltert). */
function gf_s3_zeilen(array $antwort, DateTime $now, array $st)
{
    $basis = gf_zeit_lesen($antwort['time'] ?? null, $now);
    $rows = [];
    foreach ($antwort['departures'] ?? [] as $raw) {
        $d = gf_clean_abfahrt($raw, $basis, $st['ab']);
        if ($d === null || strcasecmp($d['linie'], GF_LINIE) !== 0) {
            continue;
        }
        if ($st['ziel'] !== '' && stripos($d['ziel'], $st['ziel']) === false) {
            continue;
        }
        $d['_kurs'] = gf_kurs_schluessel($raw);
        $rows[] = $d;
    }

    return $rows;
}

/**
 * S3-Abfahrten ab Pinneberg (Richtung Hamburg) und ab dem Stadtbahnhof (nur
 * Richtung Pinneberg) in den naechsten GF_FENSTER_MIN Minuten, hoechstens
 * GF_PER_DIR je Richtung. Null, wenn beide Anfragen scheitern.
 */
function gf_departures_s3($fetch, DateTime $now, ?array $zugang = null, $stadt = GF_STADT_DEFAULT)
{
    $liste = [];
    $quellen = [];
    foreach (gf_richtungen($stadt) as $key => $st) {
        // Erst nur die S3 (Filter auf die Linien-ID), denn am Hbf fuellen sonst andere
        // Linien die Antwort, bevor die S3 der ganzen Stunde drin ist. Kommt damit nichts
        // (Filter abgelehnt oder leer), noch einmal ungefiltert mit grosser Liste.
        $anfrage = [
            'station'       => gf_station($fetch, $st['name'], $now->getTimestamp(), $zugang),
            'time'          => gf_zeit_param($now),
            'maxList'       => 40,
            'maxTimeOffset' => GF_FENSTER_MIN,
            'useRealtime'   => true,
            'filter'        => [['serviceID' => GF_S3_SERVICE_ID]],
        ];
        $antwort = gf_request('departureList', $anfrage, $fetch, $zugang);
        $rows = $antwort !== null ? gf_s3_zeilen($antwort, $now, $st) : [];
        if (!$rows) {
            unset($anfrage['filter']);
            $anfrage['maxList'] = GF_MAX_LIST;
            $antwort = gf_request('departureList', $anfrage, $fetch, $zugang);
            $rows = $antwort !== null ? gf_s3_zeilen($antwort, $now, $st) : [];
        }
        $quellen[$key] = $antwort !== null;
        if ($antwort === null) {
            continue;
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['zeit'], $b['zeit']);
        });
        $liste = array_merge($liste, array_slice($rows, 0, GF_PER_DIR));
    }
    if (!in_array(true, $quellen, true)) {
        return null;
    }

    return ['liste' => gf_s3_ankunft($fetch, $zugang, $liste, $stadt), 'quellen' => $quellen];
}

/**
 * Ankunft je S3-Zeile, wo sie fuer Pendler zaehlt: Richtung Hamburg am gewaehlten
 * Stadtbahnhof, zurueck in Pinneberg. Aus dem Laufweg (departureCourse) mit
 * Echtzeit, geteilt fuer alle Stadtbahnhoefe und je Fahrt GF_REGIO_KURS_TTL
 * Sekunden gecacht, hoechstens GF_S3_KURS_MAX Abfragen je Aufbereitung. an ist
 * {name, zeit, ist, verspaetung, ausfall} oder null.
 */
function gf_s3_ankunft($fetch, ?array $zugang, array $liste, $stadt)
{
    $kurse = nordbahn_read_json(GF_S3_KURS_CACHE);
    $kurse = is_array($kurse) ? array_filter($kurse, function ($e) {
        return is_array($e) && time() - (int) ($e['stand'] ?? 0) < GF_REGIO_KURS_TTL;
    }) : [];
    $vorher = count($kurse);
    $budget = GF_S3_KURS_MAX;
    $st = GF_STADT[$stadt] ?? GF_STADT[GF_STADT_DEFAULT];
    foreach ($liste as &$d) {
        $hin = $d['ab'] === GF_PINNEBERG;
        $ziel = $hin ? $st : ['name' => GF_PINNEBERG, 'kurz' => GF_PINNEBERG];
        $d['an'] = null;
        $kurs = !$d['ausfall'] ? gf_regio_kurs($fetch, $zugang, $d, $kurse, $budget) : null;
        foreach ($kurs['lauf'] ?? [] as $h) {
            if ($h['norm'] === gf_station_norm($ziel['name']) && $h['zeit'] !== null) {
                $d['an'] = [
                    'name'        => $ziel['kurz'],
                    'zeit'        => $h['zeit'],
                    'ist'         => $h['ist'],
                    'verspaetung' => $h['verspaetung'],
                    'ausfall'     => $h['ausfall'],
                ];
                break;
            }
        }
        unset($d['_kurs']);
    }
    unset($d);
    if (count($kurse) !== $vorher || $budget < GF_S3_KURS_MAX) {
        nordbahn_write_json(GF_S3_KURS_CACHE, $kurse);
    }

    return $liste;
}

/* ---------- DB Regio ---------- */

/**
 * Regionalzuege von DB Regio fuer Pendler an Nordbahn-Bahnhoefen, an denen auch
 * RE halten: RE6 (Westerland – Itzehoe – Glueckstadt – Elmshorn – Hamburg-Altona),
 * RE7 und RE70 (Kiel/Flensburg – Neumuenster – Elmshorn – Hamburg Hbf, einzelne
 * RE70 auch mit Halt in Wrist). Sie fahren im HVV-Tarif, der Stationsmonitor der
 * Nordbahn kennt sie nicht. IC/ICE fehlen bewusst, dort gilt der HVV-Tarif nicht.
 *
 * Hinweg: RE ab dem Heimatbahnhof Richtung Hamburg. Rueckweg: RE ab Hbf und
 * Altona nach Norden, die am Heimatbahnhof halten. In Elmshorn halten alle; an
 * den anderen Bahnhoefen halten nur einzelne Fahrten. Ob eine Fahrt dort haelt,
 * sagt ihr Laufweg (departureCourse) mit Echtzeit, je Fahrt hoechstens alle
 * GF_REGIO_KURS_TTL Sekunden geholt. Als entfallen markierte Halte zaehlen nicht. Fehlt der Laufweg, zaehlt ersatzweise, ob derselbe RE (Linie,
 * Fahrzeit im Fenster 'fahrzeit') am Heimatbahnhof nach Norden abfaehrt. Die Hamburger Tafeln teilen sich alle
 * Heimatbahnhoefe (ein Cache). Die Zeilen haben das Format von api/abfahrten,
 * damit die Startseite sie in die Nordbahn-Tafel mischen kann.
 */
const GF_REGIO_LINIEN   = ['RE6', 'RE7', 'RE70'];
// Nordbahn-ID => Name in der GTI, ob alle RE halten, Fahrzeit ab Hbf/Altona in Minuten [min, max]
const GF_REGIO_HEIMAT   = [
    'AP'   => ['name' => 'Pinneberg',  'alle' => false, 'fahrzeit' => [5, 25]],
    'AEL'  => ['name' => 'Elmshorn',   'alle' => true,  'fahrzeit' => [10, 45]],
    'AGST' => ['name' => 'Glückstadt', 'alle' => false, 'fahrzeit' => [25, 55]],
    'AIZ'  => ['name' => 'Itzehoe',    'alle' => false, 'fahrzeit' => [35, 70]],
    'AWST' => ['name' => 'Wrist',      'alle' => false, 'fahrzeit' => [30, 60]],
];
const GF_REGIO_FENSTER  = 120;   // Minuten ab Hamburg, wie die Nordbahn-Tafel
const GF_REGIO_PER_DIR  = 6;
const GF_REGIO_MAX_LIST = 300;   // ungefiltert am Hbf, zwei Stunden mit Bussen und U-Bahn
const GF_REGIO_STADT    = ['Hamburg Hbf' => 'Hbf', 'Hamburg-Altona' => 'Altona'];
const GF_REGIO_HAMBURG  = '/hamburg|altona|dammtor/i'; // nicht "hbf": Kiel Hbf
const GF_REGIO_KURS_TTL = 600;   // Sekunden, die ein Laufweg gilt; kurz, weil bei Stoerungen Halte entfallen koennen
const GF_REGIO_KURS_TTL_FERN = 1800; // hoechstens so lange fuer Fahrten, die noch weit weg sind
const GF_REGIO_KURS_NAH = 2700;  // ab 45 Minuten vor der Abfahrt gilt wieder GF_REGIO_KURS_TTL
const GF_REGIO_TTL      = 90;    // so lange gilt ein DB-Regio-Stand als frisch (RE fahren seltener als die S3)
const GF_REGIO_FILTER_TTL = 86400; // so lange gilt das Wissen, ob der Linienfilter an einer Station greift
const GF_REGIO_KURS_MAX = 12;    // Laufweg-Abfragen je Aufbereitung
define('GF_REGIO_STADT_CACHE', NORDBAHN_CACHE_DIR . '/geofox-regio-stadt.json');
define('GF_REGIO_KURS_CACHE', NORDBAHN_CACHE_DIR . '/geofox-regio-kurse.json');
define('GF_REGIO_FILTER_CACHE', NORDBAHN_CACHE_DIR . '/geofox-regio-filter.json');

function gf_regio_cache_file($heimat, $endung = 'json')
{
    return NORDBAHN_CACHE_DIR . '/geofox-regio-' . strtolower($heimat) . '.' . $endung;
}

/** Abfahrt der GTI im Format von api/abfahrten (quelle hvv, ohne Zugnummer). */
function gf_regio_zeile($raw, DateTime $basis, $ab)
{
    $d = gf_clean_abfahrt($raw, $basis, $ab);
    if ($d === null || !in_array(strtoupper($d['linie']), GF_REGIO_LINIEN, true)) {
        return null;
    }
    $norm = function ($g) {
        return preg_replace('/^gleis\s*/i', '', trim(preg_replace('/\s*\(.*?\)\s*/', ' ', nordbahn_text($g, 30))));
    };
    $plan = $norm($raw['platform'] ?? '');
    $gleisPlan = $d['gleis'] !== '' && $plan !== '' && $plan !== $d['gleis'] ? $plan : null;

    return [
        'zug'         => '',
        'zeit'        => $d['zeit'],
        'zeit_plan'   => null,
        'ist'         => $d['ist'],
        'verspaetung' => $d['delay_min'],
        'linie'       => strtoupper($d['linie']),
        'ziel'        => $d['ziel'],
        'gleis'       => $d['gleis'],
        'gleis_plan'  => $gleisPlan,
        'ausfall'     => $d['ausfall'],
        'bus'         => false,
        'hinweis'     => '',
        'wagen'       => 0,
        'ab'          => $ab,
        'ersatz'      => null,
        'teil'        => null,
        'ersatz_fuer' => null,
        'dammtor'     => null,
        'quelle'      => 'hvv',
        // Schluessel fuer departureCourse, wird vor der Ausgabe entfernt
        '_kurs'       => gf_kurs_schluessel($raw),
    ];
}

/** Was departureCourse braucht, um eine Fahrt aus departureList wiederzufinden; null ohne IDs. */
function gf_kurs_schluessel($raw)
{
    return isset($raw['line']['id'], $raw['serviceId']) ? [
        'lineKey'   => (string) $raw['line']['id'],
        'serviceId' => $raw['serviceId'],
        'direction' => (string) ($raw['line']['direction'] ?? ''),
        'origin'    => (string) ($raw['line']['origin'] ?? ''),
        'station'   => is_array($raw['station'] ?? null) ? array_intersect_key($raw['station'], ['id' => 1, 'name' => 1, 'combinedName' => 1]) : null,
    ] : null;
}

/**
 * Laufweg einer Fahrt aus departureCourse, aus dem Laufweg-Cache oder frisch:
 * ['halte' => normierte Namen der bedienten Halte (siehe gf_station_norm),
 *  'lauf' => alle Halte in Reihenfolge mit Zeiten, siehe gf_regio_lauf_lesen].
 * Null, wenn unbekannt. $budget zaehlt die erlaubten Abfragen herunter.
 */
function gf_regio_kurs($fetch, ?array $zugang, array $d, array &$cache, &$budget)
{
    $k = $d['_kurs'] ?? null;
    if (!is_array($k)) {
        return null;
    }
    $key = $k['lineKey'] . '|' . $k['serviceId'] . '|' . substr($d['zeit'], 0, 10);
    if (isset($cache[$key]['halte'])) {
        return $cache[$key];
    }
    if ($budget <= 0) {
        return null;
    }
    $budget--;
    $station = ['name' => $k['station']['combinedName'] ?? ($k['station']['name'] ?? ''), 'type' => 'STATION'];
    if (!empty($k['station']['id'])) {
        $station['id'] = $k['station']['id'];
    }
    $antwort = gf_request('departureCourse', [
        'lineKey'   => $k['lineKey'],
        'station'   => $station,
        'time'      => (new DateTime($d['zeit']))->format('Y-m-d\TH:i:s.000O'),
        'direction' => $k['direction'],
        'origin'    => $k['origin'],
        'serviceId' => $k['serviceId'],
        'segments'  => 'ALL',
        'useRealtime' => true,
    ], $fetch, $zugang);
    $lauf = gf_regio_lauf_lesen($antwort['courseElements'] ?? []);
    if (!$lauf) {
        return null;
    }
    $halte = [];
    foreach ($lauf as $h) {
        if (!$h['ausfall']) {
            $halte[$h['norm']] = true;
        }
    }
    $cache[$key] = ['halte' => array_keys($halte), 'lauf' => $lauf, 'stand' => time(), 'ttl' => gf_regio_kurs_ttl($d['zeit'])];

    return $cache[$key];
}

/**
 * Wie lange ein frisch geholter Laufweg gilt: GF_REGIO_KURS_TTL fuer Fahrten in den
 * naechsten 45 Minuten, fuer spaetere bis GF_REGIO_KURS_TTL_FERN, aber immer so, dass
 * er spaetestens 45 Minuten vor der Abfahrt neu kommt (entfallene Halte).
 */
function gf_regio_kurs_ttl($zeit, $now = null)
{
    $bis = (int) strtotime((string) $zeit) - ($now ?? time()) - GF_REGIO_KURS_NAH;

    return max(GF_REGIO_KURS_TTL, min(GF_REGIO_KURS_TTL_FERN, $bis));
}

/** Gilt ein Eintrag im Laufweg-Cache noch? */
function gf_regio_kurs_gilt($e, $now = null)
{
    return is_array($e) && ($now ?? time()) - (int) ($e['stand'] ?? 0) < (int) ($e['ttl'] ?? GF_REGIO_KURS_TTL);
}

/**
 * Laufweg-Cache schreiben, mit dem Stand auf der Platte zusammengefuehrt: Mehrere
 * Heimatbahnhoefe bauen gleichzeitig (je eigene Sperre) und wuerden sich sonst
 * gegenseitig Eintraege ueberschreiben. Je Fahrt gewinnt der juengere Stand.
 */
function gf_regio_kurse_speichern(array $kurse)
{
    $lock = @fopen(GF_REGIO_KURS_CACHE . '.lock', 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    $platte = nordbahn_read_json(GF_REGIO_KURS_CACHE);
    foreach (is_array($platte) ? $platte : [] as $key => $e) {
        if (gf_regio_kurs_gilt($e) && (!isset($kurse[$key]) || (int) ($e['stand'] ?? 0) > (int) ($kurse[$key]['stand'] ?? 0))) {
            $kurse[$key] = $e;
        }
    }
    nordbahn_write_json(GF_REGIO_KURS_CACHE, array_filter($kurse, 'gf_regio_kurs_gilt'));
    if ($lock !== false) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Halte aus den courseElements in Reihenfolge: je Halt name, norm, zeit (Plan,
 * ISO; Ankunft, am ersten Halt die Abfahrt), ist (mit Verspaetung), verspaetung
 * (Minuten), gleis und ausfall. Ein Halt zaehlt als bedient, wenn ihn ein
 * Abschnitt ohne Ausfall-Flag beruehrt: Abfahrt dort (fromStation, depCancelled
 * bzw. fromCancelled) oder Ankunft dort (toStation, arrCancelled bzw.
 * toCancelled); ein ganz ausgefallener Abschnitt (cancelled) zaehlt fuer keinen
 * der beiden. depTime/arrTime als ISO-String (oder GTITime), depDelay/arrDelay
 * in Sekunden.
 */
function gf_regio_lauf_lesen(array $elemente)
{
    $lauf = [];
    $idx = [];
    $zeit = function ($t, $delay) {
        // departureCourse liefert die Zeiten als ISO-String ("2026-11-12T16:58:00.000+0100"),
        // so wie die Anfrage sie verlangt; GTITime {date, time} bleibt als Rueckfall
        $plan = null;
        if (is_string($t) && $t !== '') {
            try {
                $plan = (new DateTime($t))->setTimezone(new DateTimeZone('Europe/Berlin'));
            } catch (Exception $e) {
                $plan = null;
            }
        } else {
            $plan = gf_zeit_lesen($t);
        }
        if ($plan === null) {
            return [null, null, 0];
        }
        $min = (int) round(((int) $delay) / 60);
        $ist = (clone $plan)->modify(($min >= 0 ? '+' : '') . $min . ' minutes');

        return [$plan->format('c'), $ist->format('c'), $min];
    };
    foreach ($elemente as $e) {
        if (!is_array($e)) {
            continue;
        }
        $teile = [
            ['fromStation', ['depCancelled', 'fromCancelled'], 'depTime', 'depDelay', ['fromRealtimePlatform', 'fromPlatform']],
            ['toStation', ['arrCancelled', 'toCancelled'], 'arrTime', 'arrDelay', ['toRealtimePlatform', 'toPlatform']],
        ];
        foreach ($teile as [$f, $flags, $tKey, $dKey, $gKeys]) {
            $name = nordbahn_text($e[$f]['combinedName'] ?? ($e[$f]['name'] ?? ''), 80);
            if ($name === '') {
                continue;
            }
            $n = gf_station_norm($name);
            $bedient = empty($e['cancelled']);
            foreach ($flags as $flag) {
                $bedient = $bedient && empty($e[$flag]);
            }
            $gleis = '';
            foreach ($gKeys as $g) {
                if (!empty($e[$g])) {
                    $gleis = preg_replace('/^gleis\s*/i', '', trim(preg_replace('/\s*\(.*?\)\s*/', ' ', nordbahn_text($e[$g], 30))));
                    break;
                }
            }
            [$plan, $ist, $min] = $zeit($e[$tKey] ?? null, $e[$dKey] ?? 0);
            if (!isset($idx[$n])) {
                $idx[$n] = count($lauf);
                $lauf[] = ['name' => $name, 'norm' => $n, 'zeit' => $plan, 'ist' => $ist, 'verspaetung' => $min, 'gleis' => $gleis, 'ausfall' => !$bedient];
                continue;
            }
            // Zweite Erwaehnung (Ankunft dort, dann Abfahrt): bedient, wenn eine von beiden zaehlt;
            // die Zeit bleibt die Ankunft, das Gleis kommt notfalls aus der Abfahrt
            $h = &$lauf[$idx[$n]];
            $h['ausfall'] = $h['ausfall'] && !$bedient;
            if ($h['zeit'] === null && $plan !== null) {
                [$h['zeit'], $h['ist'], $h['verspaetung']] = [$plan, $ist, $min];
            }
            if ($h['gleis'] === '') {
                $h['gleis'] = $gleis;
            }
            unset($h);
        }
    }

    return $lauf;
}

/**
 * Laufweg fuer die Tafel ab dem Einstieg ($ab, Name der Station) bis zum Ende,
 * Format wie abf_lauf (Zeiten als HH:MM, heimat markiert). Null ohne Einstieg.
 */
function gf_regio_lauf_tafel(array $lauf, $ab, $heimat)
{
    $start = gf_station_norm($ab);
    $ziel = gf_station_norm($heimat);
    $out = [];
    foreach ($lauf as $h) {
        if (!$out && $h['norm'] !== $start) {
            continue;
        }
        $uhr = function ($iso) {
            return $iso ? (new DateTime($iso))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i') : '';
        };
        $out[] = [
            'id'          => '',
            'name'        => $h['name'],
            'zeit'        => $uhr($h['zeit']),
            'ist'         => $h['verspaetung'] !== 0 ? $uhr($h['ist']) : null,
            'verspaetung' => $h['verspaetung'],
            'gleis'       => $h['gleis'],
            'ausfall'     => $h['ausfall'],
            'heimat'      => $h['norm'] === $ziel,
        ];
    }

    return count($out) >= 2 ? $out : null;
}

/** Faehrt die Zeile Richtung Hamburg? */
function gf_regio_nach_hamburg(array $d)
{
    return (bool) preg_match(GF_REGIO_HAMBURG, $d['ziel']);
}

/**
 * Alle RE-Zeilen einer Station in den naechsten $fenster Minuten, beide
 * Richtungen. Erst mit Linienfilter (serviceName); nimmt die GTI den nicht an
 * (Fehler, leer oder auch fremde Linien in der Antwort), noch einmal ungefiltert
 * mit grosser Liste. Null bei Netzfehler.
 *
 * Ob der Filter an der Station greift, merkt sich GF_REGIO_FILTER_CACHE einen Tag
 * lang (gf_regio_filter_wissen): Greift er, ist eine leere Antwort einfach leer
 * (nachts), und die grosse ungefilterte Liste entfaellt. Greift er nicht, geht
 * die Anfrage gleich ungefiltert raus.
 */
function gf_regio_station($fetch, DateTime $now, ?array $zugang, $station, $ab, $fenster)
{
    $anfrage = [
        'station'       => gf_station($fetch, $station, $now->getTimestamp(), $zugang),
        'time'          => gf_zeit_param($now),
        'maxList'       => 60,
        'maxTimeOffset' => $fenster,
        'useRealtime'   => true,
        'filter'        => array_map(function ($l) { return ['serviceName' => $l]; }, GF_REGIO_LINIEN),
    ];
    $fremd = false;
    $lesen = function ($antwort) use ($now, $ab, &$fremd) {
        $basis = gf_zeit_lesen($antwort['time'] ?? null, $now);
        $rows = [];
        $fremd = false;
        foreach ($antwort['departures'] ?? [] as $raw) {
            $d = gf_regio_zeile($raw, $basis, $ab);
            if ($d === null) {
                $fremd = true;
                continue;
            }
            $rows[] = $d;
        }

        return $rows;
    };
    $ungefiltert = function () use (&$anfrage, $fetch, $zugang, $lesen) {
        unset($anfrage['filter']);
        $anfrage['maxList'] = GF_REGIO_MAX_LIST;
        $antwort = gf_request('departureList', $anfrage, $fetch, $zugang);

        return $antwort !== null ? $lesen($antwort) : null;
    };
    $wissen = gf_regio_filter_wissen($station, $now->getTimestamp());
    if ($wissen === false) {
        return $ungefiltert();
    }
    $antwort = gf_request('departureList', $anfrage, $fetch, $zugang);
    if ($antwort === null && $wissen === true) {
        return null; // Netzfehler, der Filter selbst ist bekannt gut
    }
    $rows = $antwort !== null ? $lesen($antwort) : [];
    if ($antwort !== null && !$fremd && ($rows || $wissen === true)) {
        if ($rows) {
            gf_regio_filter_merken($station, true, $now->getTimestamp());
        }

        return $rows;
    }
    $gefiltertLeer = $antwort !== null && !$rows && !$fremd;
    $rows = $ungefiltert();
    // Fremde Linien trotz Filter, oder gefiltert leer und ungefiltert mit RE: der Filter greift nicht
    if ($rows !== null && ($fremd || ($gefiltertLeer && $rows))) {
        gf_regio_filter_merken($station, false, $now->getTimestamp());
    }

    return $rows;
}

/** Greift der Linienfilter an der Station? true, false oder null (unbekannt oder veraltet). */
function gf_regio_filter_wissen($station, $now)
{
    $c = nordbahn_read_json(GF_REGIO_FILTER_CACHE);
    $e = is_array($c) ? ($c[$station] ?? null) : null;
    if (!is_array($e) || !isset($e['ok']) || $now - (int) ($e['stand'] ?? 0) >= GF_REGIO_FILTER_TTL) {
        return null;
    }

    return (bool) $e['ok'];
}

function gf_regio_filter_merken($station, $ok, $now)
{
    $c = nordbahn_read_json(GF_REGIO_FILTER_CACHE);
    $c = is_array($c) ? $c : [];
    if (isset($c[$station]['ok']) && $c[$station]['ok'] === $ok && $now - (int) ($c[$station]['stand'] ?? 0) < GF_REGIO_FILTER_TTL / 2) {
        return; // schon bekannt, nicht bei jedem Aufbau schreiben
    }
    $c[$station] = ['ok' => (bool) $ok, 'stand' => (int) $now];
    nordbahn_write_json(GF_REGIO_FILTER_CACHE, $c);
}

/**
 * RE ab Hbf und Altona nach Norden, geteilt fuer alle Heimatbahnhoefe und
 * hoechstens GF_TTL Sekunden alt. Null, wenn beide Tafeln fehlen.
 */
function gf_regio_stadt($fetch, DateTime $now, ?array $zugang)
{
    $cache = nordbahn_read_json(GF_REGIO_STADT_CACHE);
    if (is_array($cache) && isset($cache['fetched_at']) && is_array($cache['zeilen'] ?? null)
        && abs($now->getTimestamp() - strtotime($cache['fetched_at'])) < GF_REGIO_TTL) {
        return $cache['zeilen'];
    }
    $zeilen = null;
    foreach (GF_REGIO_STADT as $station => $ab) {
        $rows = gf_regio_station($fetch, $now, $zugang, $station, $ab, GF_REGIO_FENSTER);
        if ($rows === null) {
            continue;
        }
        foreach ($rows as $d) {
            if (!gf_regio_nach_hamburg($d)) {
                $zeilen[] = $d;
            }
        }
        $zeilen = $zeilen ?? [];
    }
    if ($zeilen !== null) {
        nordbahn_write_json(GF_REGIO_STADT_CACHE, ['fetched_at' => $now->format('c'), 'zeilen' => $zeilen]);
    }

    return $zeilen;
}

/**
 * Hamburger Abfahrten, die am Heimatbahnhof halten: je Abfahrt nach Norden am
 * Heimatbahnhof die juengste Hamburger Abfahrt derselben Linie, deren Planzeit
 * im Fahrzeitfenster davor liegt. Jede Hamburger Abfahrt zaehlt hoechstens einmal.
 */
function gf_regio_halten(array $stadt, array $nord, array $fahrzeit)
{
    [$min, $max] = $fahrzeit;
    usort($nord, function ($a, $b) { return strcmp($a['zeit'], $b['zeit']); });
    $vergeben = [];
    $out = [];
    foreach ($nord as $n) {
        $t = strtotime($n['zeit']);
        $best = null;
        foreach ($stadt as $i => $s) {
            if (isset($vergeben[$i]) || $s['linie'] !== $n['linie']) {
                continue;
            }
            $diff = ($t - strtotime($s['zeit'])) / 60;
            if ($diff < $min || $diff > $max) {
                continue;
            }
            if ($best === null || $s['zeit'] > $stadt[$best]['zeit']) {
                $best = $i;
            }
        }
        if ($best !== null) {
            $vergeben[$best] = true;
            $out[] = $stadt[$best];
        }
    }

    return $out;
}

/**
 * Stand fuer einen Heimatbahnhof: ok, fetched_at, heimat {id, name}, linien,
 * quellen {hin, rueck}, richtungen {hin, rueck}. Null, wenn nichts zu holen war
 * oder am Bahnhof keine RE halten koennen.
 */
function gf_regio_build($heimat, $fetch = null, ?DateTime $now = null, ?array $zugang = null)
{
    if (!isset(GF_REGIO_HEIMAT[$heimat])) {
        return null;
    }
    $cfg = GF_REGIO_HEIMAT[$heimat];
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $sort = function ($a, $b) {
        return strcmp($a['ist'] ?: $a['zeit'], $b['ist'] ?: $b['zeit']);
    };
    $cutoff = (clone $now)->modify('-1 minute')->format('c');
    $kommend = function (array $rows) use ($cutoff, $sort) {
        $rows = array_values(array_filter($rows, function ($d) use ($cutoff) {
            return ($d['ist'] ?: $d['zeit']) >= $cutoff;
        }));
        usort($rows, $sort);

        return array_slice($rows, 0, GF_REGIO_PER_DIR);
    };
    // Laufwege je Fahrt, geteilt fuer alle Heimatbahnhoefe
    $kurse = nordbahn_read_json(GF_REGIO_KURS_CACHE);
    $kurse = is_array($kurse) ? array_filter($kurse, 'gf_regio_kurs_gilt') : [];
    $vorher = count($kurse);
    $budget = GF_REGIO_KURS_MAX;

    // Am Heimatbahnhof so weit voraus, dass auch der letzte Hamburger RE im Fenster dort ankommt
    $amOrt = gf_regio_station($fetch, $now, $zugang, $cfg['name'], '', GF_REGIO_FENSTER + $cfg['fahrzeit'][1]);
    $hin = null;
    $nord = [];
    if ($amOrt !== null) {
        $hin = [];
        foreach ($amOrt as $d) {
            if (gf_regio_nach_hamburg($d)) {
                $hin[] = $d;
            } else {
                $nord[] = $d;
            }
        }
    }
    $stadt = gf_regio_stadt($fetch, $now, $zugang);
    $rueck = null;
    if ($stadt !== null && $cfg['alle']) {
        $rueck = $stadt;
    } elseif ($stadt !== null) {
        // Laufweg je Fahrt; ohne Laufweg der Abgleich ueber die Fahrzeit
        $ziel = gf_station_norm($cfg['name']);
        $rueck = [];
        $offen = [];
        foreach ($stadt as $d) {
            $kurs = gf_regio_kurs($fetch, $zugang, $d, $kurse, $budget);
            $halte = $kurs['halte'] ?? null;
            if ($halte === null) {
                $offen[] = $d;
            } elseif (in_array($ziel, $halte, true)) {
                $rueck[] = $d;
            }
        }
        if ($offen && $amOrt !== null) {
            $rueck = array_merge($rueck, gf_regio_halten($offen, $nord, $cfg['fahrzeit']));
        }
    }
    if ($hin === null && $rueck === null) {
        return null;
    }

    // Laufweg zum Aufklappen fuer die gezeigten Zeilen; was das Budget nicht mehr
    // schafft, kommt mit der naechsten Aufbereitung
    $richtungen = [];
    foreach (['hin' => $hin, 'rueck' => $rueck] as $dir => $rows) {
        $richtungen[$dir] = array_map(function ($d) use ($fetch, $zugang, &$kurse, &$budget, $cfg) {
            $kurs = gf_regio_kurs($fetch, $zugang, $d, $kurse, $budget);
            $ab = $d['_kurs']['station']['name'] ?? ($d['ab'] !== '' ? array_search($d['ab'], GF_REGIO_STADT, true) : $cfg['name']);
            $d['lauf'] = isset($kurs['lauf']) ? gf_regio_lauf_tafel($kurs['lauf'], (string) $ab, $cfg['name']) : null;
            unset($d['_kurs']);

            return $d;
        }, $kommend($rows ?? []));
    }
    if (count($kurse) !== $vorher || $budget < GF_REGIO_KURS_MAX) {
        gf_regio_kurse_speichern($kurse);
    }

    return [
        'ok'         => true,
        'fetched_at' => $now->format('c'),
        'heimat'     => ['id' => $heimat, 'name' => NORDBAHN_BAHNHOEFE[$heimat]['name'] ?? $cfg['name']],
        'linien'     => GF_REGIO_LINIEN,
        'quellen'    => ['hin' => $hin !== null, 'rueck' => $rueck !== null],
        'richtungen' => $richtungen,
    ];
}

function gf_regio_leer()
{
    return ['ok' => false, 'fetched_at' => null, 'richtungen' => null];
}

/** Stand aus dem Cache des Heimatbahnhofs, bei Bedarf erneuert (wie gf_cached); neue Staende gehen ins Zuglog. */
function gf_regio_cached($heimat, $fetch = null, ?DateTime $now = null, ?array $zugang = null)
{
    $zugang = $zugang ?? gf_zugang();
    if (($zugang === null && $fetch === null) || !isset(GF_REGIO_HEIMAT[$heimat])) {
        return gf_regio_leer();
    }

    return gf_cache_lauf(gf_regio_cache_file($heimat), gf_regio_cache_file($heimat, 'lock'), $now, function () use ($heimat, $fetch, $now, $zugang) {
        $r = gf_regio_build($heimat, $fetch, $now, $zugang);
        if ($r !== null) {
            // Fuer die Statistik ins Zuglog, wie die Nordbahn-Abfahrten
            require_once __DIR__ . '/zuglog.php';
            zl_log($r, $heimat, $now ? $now->getTimestamp() : null);
        }

        return $r;
    }, gf_regio_leer(), GF_REGIO_TTL);
}

/* ---------- Meldungen ---------- */

/** HTML einer Meldung zu Text: Absaetze und Umbrueche bleiben als Zeilenumbruch. */
function gf_strip_html($html)
{
    $t = preg_replace('#<\s*(br|/p|/li|/div|/h\d)\s*/?>#i', "\n", (string) $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00a0}]+/u', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);

    return trim($t);
}

/** Stationsname fuer den Vergleich: "Hamburg Hbf" -> "hauptbahnhof", "Hamburg-Altona" -> "altona". */
function gf_station_norm($name)
{
    $n = mb_strtolower(trim((string) $name), 'UTF-8');
    $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $n = preg_replace('/\(.*?\)/', '', $n);
    $n = preg_replace('/^(hamburg|hh|s)[\s-]+/', '', $n);
    $n = preg_replace('/\bhbf\b/', 'hauptbahnhof', $n);

    return trim(preg_replace('/[^a-z0-9]+/', '', $n));
}

/**
 * Linien einer Meldung, die Pendler auf unserer Strecke betreffen: aus locations[]
 * (Linie mit Abschnitt von/bis) oder, ohne locations, aus lines[]. Die S3 zaehlt nur,
 * wenn der Titel sie nennt (GF_S3_IM_TITEL) und, bei locations, der Abschnitt im
 * Korridor Pinneberg - Innenstadt beginnt oder endet.
 */
function gf_meldung_linien(array $raw)
{
    $out = [];
    $add = function ($name) use (&$out) {
        $name = str_replace(' ', '', nordbahn_text($name, 20));
        if ($name !== '' && !in_array($name, $out, true)) {
            $out[] = $name;
        }
    };
    $s3ImTitel = preg_match(GF_S3_IM_TITEL, gf_strip_html(nordbahn_text($raw['summary'] ?? ''))) === 1;
    $locations = is_array($raw['locations'] ?? null) ? $raw['locations'] : [];
    foreach ($locations as $loc) {
        if (!is_array($loc) || !is_array($loc['line'] ?? null)) {
            continue;
        }
        $linie = str_replace(' ', '', (string) ($loc['line']['name'] ?? ''));
        if (strcasecmp($linie, GF_LINIE) === 0) {
            $von = gf_station_norm($loc['begin']['name'] ?? '');
            $bis = gf_station_norm($loc['end']['name'] ?? '');
            if (!$s3ImTitel || (!in_array($von, GF_S3_KORRIDOR, true) && !in_array($bis, GF_S3_KORRIDOR, true))) {
                continue;
            }
        }
        $add($linie);
    }
    if (!$locations) {
        foreach ($raw['lines'] ?? [] as $l) {
            $linie = is_array($l) ? ($l['name'] ?? '') : $l;
            if (!is_scalar($linie) || (!$s3ImTitel && strcasecmp(str_replace(' ', '', (string) $linie), GF_LINIE) === 0)) {
                continue;
            }
            $add($linie);
        }
    }

    return $out;
}

/**
 * Eine Meldung der GTI in unser Format: id, titel, text, geplant, aktiv (jetzt
 * gueltig), von, bis (ISO UTC), linien, stand, grund. Null ohne id oder Titel.
 */
function gf_clean_meldung($raw, $now)
{
    if (!is_array($raw) || !isset($raw['id']) || trim((string) ($raw['summary'] ?? '')) === '') {
        return null;
    }
    $von = null;
    $bis = null;
    $aktiv = false;
    $validities = is_array($raw['validities'] ?? null) ? $raw['validities'] : [];
    foreach ($validities as $v) {
        $b = gf_ts($v['begin'] ?? null);
        $e = gf_ts($v['end'] ?? null);
        if ($b !== null && ($von === null || $b < $von)) {
            $von = $b;
        }
        if ($e !== null && ($bis === null || $e > $bis)) {
            $bis = $e;
        }
        if (($b === null || $b <= $now) && ($e === null || $e >= $now)) {
            $aktiv = true;
        }
    }
    if (!$validities) {
        $aktiv = true;
    }

    return [
        'id'      => nordbahn_text($raw['id'], 60),
        'version' => (int) ($raw['version'] ?? 0),
        'titel'   => gf_strip_html($raw['summary']),
        'text'    => nordbahn_text(gf_strip_html($raw['description'] ?? ''), 1500),
        'geplant' => !empty($raw['planned']),
        'aktiv'   => $aktiv,
        'von'     => $von !== null ? gmdate('Y-m-d\TH:i:s\Z', $von) : '',
        'bis'     => $bis !== null ? gmdate('Y-m-d\TH:i:s\Z', $bis) : '',
        'linien'  => gf_meldung_linien($raw),
        'stand'   => nordbahn_iso($raw['lastModified'] ?? ''),
        'grund'   => nordbahn_text($raw['reason'] ?? '', 40),
        'link'    => gf_meldung_link($raw),
    ];
}

/** Erster brauchbarer Link einer Meldung (http/https), sonst leer. */
function gf_meldung_link(array $raw)
{
    foreach ($raw['links'] ?? [] as $l) {
        $url = is_array($l) ? trim((string) ($l['url'] ?? '')) : '';
        if (preg_match('#^https?://#i', $url) && strlen($url) <= 500) {
            return $url;
        }
    }

    return '';
}

function gf_ts($value)
{
    if (!is_scalar($value) || trim((string) $value) === '') {
        return null;
    }
    $ts = strtotime((string) $value);

    return $ts === false ? null : $ts;
}

/** Anfrageformen fuer getAnnouncements, der Reihe nach probiert: mit Zeitraum, nur Linien, ohne Filter. */
function gf_announcement_varianten(array $linien, $ts)
{
    // Format wie in den Antworten der GTI (lastUpdate, validities): 2026-10-20T08:12:33.000+0200
    $von = (new DateTime('@' . ($ts - 3600)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i:s.000O');
    $bis = (new DateTime('@' . ($ts + 7 * 86400)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i:s.000O');

    return [
        ['names' => $linien, 'timeRange' => ['begin' => $von, 'end' => $bis], 'full' => true, 'filterPlanned' => 'NO_FILTER'],
        ['names' => $linien, 'full' => true],
        ['full' => true],
    ];
}

/**
 * HVV-Meldungen zu den Linien in $linien: aktive ungeplante zuerst, dann aktive
 * geplante, dann nach Beginn absteigend; hoechstens GF_MELDUNGEN_MAX. Null bei Fehler.
 */
function gf_announcements($fetch, DateTime $now, array $linien = GF_LINIEN, ?array $zugang = null)
{
    $ts = $now->getTimestamp();
    // Drei Anfrageformen, bis eine Antwort kommt: mit Zeitraum, nur Linien, ohne Filter
    // (die Linien werden unten ohnehin noch einmal geprueft)
    $varianten = gf_announcement_varianten($linien, $ts);
    $antwort = null;
    foreach ($varianten as $body) {
        $antwort = gf_request('getAnnouncements', $body, $fetch, $zugang);
        if ($antwort !== null) {
            break;
        }
    }
    if ($antwort === null) {
        return null;
    }
    $out = [];
    foreach ($antwort['announcements'] ?? [] as $raw) {
        $m = gf_clean_meldung($raw, $ts);
        if ($m === null) {
            continue;
        }
        // Nur Meldungen zu unseren Linien, und keine zu Aufzuegen und Rolltreppen
        if (!array_intersect($m['linien'], $linien) || preg_match(GF_MELDUNG_AUSSCHLUSS, $m['titel'] . ' ' . $m['grund'])) {
            continue;
        }
        $out[] = $m;
    }
    usort($out, function ($a, $b) {
        // aktive ungeplante (0), aktive geplante (1), alle anderen (2) nach Beginn absteigend
        $ra = $a['aktiv'] ? ($a['geplant'] ? 1 : 0) : 2;
        $rb = $b['aktiv'] ? ($b['geplant'] ? 1 : 0) : 2;

        return $ra <=> $rb ?: strcmp($b['von'], $a['von']);
    });

    return array_slice($out, 0, GF_MELDUNGEN_MAX);
}

/* ---------- Status ---------- */

/**
 * Lagekarte fuer die S3: level, title, detail. Sie richtet sich allein nach den gemessenen
 * Abfahrten. HVV-Meldungen faerben sie nicht: ihre Zuordnung zur S3 stimmt zu oft nicht
 * (siehe GF_S3_IM_TITEL), und was die Zuege wirklich trifft, steht in den Abfahrten.
 */
function gf_status(array $abfahrten)
{
    $total = count($abfahrten);
    $ausfaelle = 0;
    $verspaetet = 0;
    $maxDelay = 0;
    foreach ($abfahrten as $d) {
        if (!empty($d['ausfall'])) {
            $ausfaelle++;
        } elseif (($d['delay_min'] ?? 0) >= GF_DELAY_MIN) {
            $verspaetet++;
            $maxDelay = max($maxDelay, (int) $d['delay_min']);
        }
    }
    $betroffen = $ausfaelle + $verspaetet;
    $zahlen = '';
    if ($betroffen) {
        $parts = [];
        if ($ausfaelle) {
            $parts[] = $ausfaelle === 1 ? '1 Ausfall' : $ausfaelle . ' Ausfälle';
        }
        if ($verspaetet) {
            $parts[] = 'Verspätungen bis ' . $maxDelay . ' Min';
        }
        $zahlen = $betroffen . ' von ' . $total . ' Zügen (' . (int) round(100 * $betroffen / max(1, $total)) . ' %): ' . implode(', ', $parts);
    }
    if (!$total) {
        return ['level' => 'ok', 'title' => 'S3: keine Abfahrten gemeldet', 'detail' => ''];
    }
    $anteil = $betroffen / $total;
    if ($anteil >= GF_ALERT_ANTEIL) {
        return ['level' => 'alert', 'title' => 'S3 gestört', 'detail' => $zahlen];
    }
    // Die S3 ist fuer Pendler nur Plan B und faehrt dicht: einzelne kleine Verspaetungen
    // faerben die Karte nicht, erst ein spuerbarer Anteil, mehrere Ausfaelle oder eine grosse Verspaetung
    if ($anteil >= GF_WARN_ANTEIL || $ausfaelle >= GF_WARN_AUSFAELLE || $maxDelay >= GF_WARN_DELAY) {
        return ['level' => 'warn', 'title' => 'S3 beeinträchtigt', 'detail' => $zahlen];
    }
    if ($betroffen) {
        return ['level' => 'ok', 'title' => 'S3 fährt weitgehend planmäßig', 'detail' => $zahlen];
    }

    return ['level' => 'ok', 'title' => 'S3 fährt planmäßig', 'detail' => ''];
}

/** Meldungen aus dem geteilten Cache (alle Stadtbahnhoefe), hoechstens GF_TTL alt, sonst frisch; null bei Fehler ohne Cache. */
function gf_announcements_cached($fetch, DateTime $now, ?array $zugang = null)
{
    $cache = nordbahn_read_json(GF_MELDUNGEN_CACHE);
    $ts = $now->getTimestamp();
    if (is_array($cache) && isset($cache['fetched_at'], $cache['meldungen']) && $ts - strtotime($cache['fetched_at']) < GF_TTL) {
        return $cache['meldungen'];
    }
    $meldungen = gf_announcements($fetch, $now, GF_LINIEN, $zugang);
    if ($meldungen !== null) {
        nordbahn_write_json(GF_MELDUNGEN_CACHE, ['fetched_at' => $now->format('c'), 'meldungen' => $meldungen]);

        return $meldungen;
    }

    return is_array($cache) && is_array($cache['meldungen'] ?? null) ? $cache['meldungen'] : null;
}

/* ---------- Aufbau und Cache ---------- */

/**
 * Kompletter Stand fuer einen Stadtbahnhof: ok, fetched_at, stadt {id, name, kurz},
 * quellen {hin, rueck, meldungen}, s3 {level, title, detail, abfahrten, meldungen}.
 * Null, wenn keine Abfahrten zu holen waren.
 * Spaeter moeglich: Push bei neuer ungeplanter S3-Meldung (notify.php, id hvv:<id>:<version>).
 */
function gf_build($fetch = null, ?DateTime $now = null, ?array $zugang = null, $stadt = GF_STADT_DEFAULT)
{
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $stadt = isset(GF_STADT[$stadt]) ? $stadt : GF_STADT_DEFAULT;
    $abf = gf_departures_s3($fetch, $now, $zugang, $stadt);
    if ($abf === null) {
        return null;
    }
    $meldungen = gf_announcements_cached($fetch, $now, $zugang);
    $status = gf_status($abf['liste']);

    return [
        'ok'         => true,
        'fetched_at' => $now->format('c'),
        'stadt'      => ['id' => $stadt] + GF_STADT[$stadt],
        'quellen'    => $abf['quellen'] + ['meldungen' => $meldungen !== null],
        's3'         => $status + ['abfahrten' => $abf['liste'], 'meldungen' => $meldungen ?? []],
    ];
}

function gf_leer()
{
    return ['ok' => false, 'fetched_at' => null, 's3' => null];
}

/** Stand aus dem Cache des Stadtbahnhofs, bei Bedarf erneuert (ein Prozess fragt, die anderen warten nicht). */
function gf_cached($fetch = null, ?DateTime $now = null, ?array $zugang = null, $stadt = GF_STADT_DEFAULT)
{
    $zugang = $zugang ?? gf_zugang();
    if ($zugang === null && $fetch === null) {
        return gf_leer();
    }

    return gf_cache_lauf(gf_cache_file($stadt), gf_cache_file($stadt, 'lock'), $now, function () use ($fetch, $now, $zugang, $stadt) {
        return gf_build($fetch, $now, $zugang, $stadt);
    }, gf_leer());
}

/**
 * Cache mit Sperre: frischer Stand (juenger als GF_TTL) direkt, sonst baut ein
 * Prozess neu und die anderen liefern den alten Stand. Scheitert der Aufbau,
 * bleibt der alte Inhalt mit ok=false, ohne Cache kommt $leer.
 */
function gf_cache_lauf($cacheFile, $lockFile, ?DateTime $now, callable $build, array $leer, $ttl = GF_TTL)
{
    $jetzt = $now ? $now->getTimestamp() : time();
    $cached = nordbahn_read_json($cacheFile);
    if (is_array($cached) && isset($cached['fetched_at']) && $jetzt - strtotime($cached['fetched_at']) < $ttl) {
        return $cached;
    }
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen($lockFile, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = $build();
        if ($result !== null) {
            nordbahn_write_json($cacheFile, $result);
            $cached = $result;
        } elseif (is_array($cached)) {
            $cached['ok'] = false;
        } else {
            $cached = $leer;
        }
        flock($lock, LOCK_UN);
    } elseif (!is_array($cached)) {
        $cached = $leer;
    }
    if ($lock !== false) {
        fclose($lock);
    }

    return $cached;
}

/* ---------- Diagnose ---------- */

/**
 * Rohantworten der wichtigsten Methoden, um das Antwortformat ohne SSH zu pruefen
 * (api/s3?roh=<NORDBAHN_PUSH_TOKEN>). Je Aufruf: anfrage, status, antwort
 * (dekodiert, sonst Rohtext). Zugangsdaten erscheinen nicht.
 * $fetchRoh: function ($method, $json) -> ['status', 'body'] (Tests).
 */
function gf_diagnose(array $zugang, ?DateTime $now = null, $fetchRoh = null)
{
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $ts = $now->getTimestamp();
    $fetchRoh = $fetchRoh ?? function ($method, $json) use ($zugang) {
        return gf_fetch_roh($method, $json, $zugang);
    };
    $aufrufe = [
        'init'                     => ['init', []],
        'checkName Pinneberg'      => ['checkName', ['theName' => ['name' => GF_PINNEBERG, 'type' => 'STATION'], 'maxList' => 5]],
        'checkName Hamburg Hbf'    => ['checkName', ['theName' => ['name' => 'Hamburg Hbf', 'type' => 'STATION'], 'maxList' => 5]],
        'checkName Jungfernstieg'  => ['checkName', ['theName' => ['name' => 'Jungfernstieg', 'type' => 'STATION'], 'maxList' => 5]],
        'checkName Hamburg-Altona' => ['checkName', ['theName' => ['name' => 'Hamburg-Altona', 'type' => 'STATION'], 'maxList' => 5]],
        'checkName Stadthausbrücke' => ['checkName', ['theName' => ['name' => 'Stadthausbrücke', 'type' => 'STATION'], 'maxList' => 5]],
        'departureList Pinneberg gefiltert' => ['departureList', ['station' => ['name' => GF_PINNEBERG, 'type' => 'STATION'], 'time' => gf_zeit_param($now), 'maxList' => 40, 'maxTimeOffset' => GF_FENSTER_MIN, 'useRealtime' => true, 'filter' => [['serviceID' => GF_S3_SERVICE_ID]]]],
        'departureList Hamburg Hbf gefiltert' => ['departureList', ['station' => ['name' => 'Hamburg Hbf', 'type' => 'STATION'], 'time' => gf_zeit_param($now), 'maxList' => 40, 'maxTimeOffset' => GF_FENSTER_MIN, 'useRealtime' => true, 'filter' => [['serviceID' => GF_S3_SERVICE_ID]]]],
        // DB Regio ab Elmshorn: zeigt Liniennamen und -IDs der RE und ob der Filter per serviceName greift
        'departureList Elmshorn RE gefiltert' => ['departureList', ['station' => ['name' => 'Elmshorn', 'type' => 'STATION'], 'time' => gf_zeit_param($now), 'maxList' => 40, 'maxTimeOffset' => GF_REGIO_FENSTER, 'useRealtime' => true, 'filter' => array_map(function ($l) { return ['serviceName' => $l]; }, GF_REGIO_LINIEN)]],
        'departureList Elmshorn ungefiltert' => ['departureList', ['station' => ['name' => 'Elmshorn', 'type' => 'STATION'], 'time' => gf_zeit_param($now), 'maxList' => 40, 'maxTimeOffset' => 60, 'useRealtime' => true]],
    ];
    foreach (gf_announcement_varianten(GF_LINIEN, $ts) as $i => $body) {
        $aufrufe['getAnnouncements Variante ' . ($i + 1)] = ['getAnnouncements', $body];
    }
    $out = ['url' => $zugang['url'], 'version' => $zugang['version'], 'zeit' => $now->format('c'), 'aufrufe' => []];
    foreach ($aufrufe as $label => [$method, $body]) {
        $body = ['version' => $zugang['version'], 'language' => 'de'] + $body;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = $fetchRoh($method, $json);
        $dekodiert = json_decode((string) ($r['body'] ?? ''), true);
        $out['aufrufe'][$label] = [
            'methode' => $method,
            'anfrage' => $body,
            'status'  => (int) ($r['status'] ?? 0),
            'antwort' => is_array($dekodiert) ? $dekodiert : nordbahn_text($r['body'] ?? '', 4000),
        ];
    }

    // Zuglauf der ersten S3 ab Pinneberg: zeigt, ob die GTI Fahrzeug- oder Laengenangaben
    // (3, 6 oder 9 Wagen) kennt. departureList hat dafuer kein Feld.
    $erste = $out['aufrufe']['departureList Pinneberg gefiltert']['antwort']['departures'][0] ?? null;
    if (is_array($erste) && isset($erste['serviceId'], $erste['line']['id'])) {
        $body = ['version' => $zugang['version'], 'language' => 'de',
            'lineKey'   => $erste['line']['id'],
            'station'   => ['name' => GF_PINNEBERG, 'type' => 'STATION'] + (isset($erste['station']['id']) ? ['id' => $erste['station']['id']] : []),
            'time'      => (clone $now)->modify('+' . (int) ($erste['timeOffset'] ?? 0) . ' minutes')->format('Y-m-d\TH:i:s.000O'),
            'direction' => $erste['line']['direction'] ?? '',
            'origin'    => $erste['line']['origin'] ?? '',
            'serviceId' => $erste['serviceId'],
            'segments'  => 'ALL',
        ];
        $r = $fetchRoh('departureCourse', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $dekodiert = json_decode((string) ($r['body'] ?? ''), true);
        $out['aufrufe']['departureCourse erste S3 ab Pinneberg'] = [
            'methode' => 'departureCourse',
            'anfrage' => $body,
            'status'  => (int) ($r['status'] ?? 0),
            'antwort' => is_array($dekodiert) ? $dekodiert : nordbahn_text($r['body'] ?? '', 4000),
        ];
    }

    // Anfragen der Seite je Tag (ohne diese Diagnose), um sie mit dem Limit zu vergleichen
    $out['anfragen'] = gf_zaehler_uebersicht();

    return $out;
}
