<?php
/**
 * HVV-Schnittstelle Geofox GTI: S-Bahn S3 zwischen Pinneberg und Hamburg sowie
 * HVV-Meldungen zu S3, RB61 und RB71. Reine Bibliothek, gibt nichts aus.
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
const GF_MELDUNG_AUSSCHLUSS = '/aufzug|aufzüge|fahrstuhl|rolltreppe|\\blift\\b|barrierefrei|fahrtreppe|elevator|escalator/iu';
const GF_DELAY_MIN    = 5;         // ab dieser Verspaetung (Minuten) zaehlt ein Zug als betroffen
const GF_ALERT_ANTEIL = 0.25;      // ab diesem Anteil "S3 gestoert"
const GF_LINIE        = 'S3';
const GF_LINIEN       = ['S3', 'RB61', 'RB71'];
const GF_MELDUNGEN_MAX = 20;
const GF_STATIONEN_TTL = 86400;    // Stations-IDs einmal am Tag auffrischen
const GF_PINNEBERG = 'Pinneberg';
// Stadtbahnhoefe fuer die Rueckrichtung (?stadt=), waehlbar auf der Startseite
const GF_STADT = [
    'hbf'           => ['name' => 'Hamburg Hbf',    'kurz' => 'Hbf'],
    'jungfernstieg' => ['name' => 'Jungfernstieg',  'kurz' => 'Jungfernstieg'],
    'altona'        => ['name' => 'Hamburg-Altona', 'kurz' => 'Altona'],
];
const GF_STADT_DEFAULT = 'hbf';
define('GF_CACHE', NORDBAHN_CACHE_DIR . '/geofox-' . GF_STADT_DEFAULT . '.json');
define('GF_MELDUNGEN_CACHE', NORDBAHN_CACHE_DIR . '/geofox-meldungen.json');
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

/** POST an die GTI: ['status' => HTTP-Status (0 ohne Verbindung), 'body' => Antworttext]. */
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
        ]);
        $antwort = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $antwort === false ? '' : (string) $antwort];
    }
    $antwort = @file_get_contents($url, false, stream_context_create([
        'http' => ['method' => 'POST', 'timeout' => GF_TIMEOUT, 'user_agent' => nordbahn_site_host() . ' Monitor', 'header' => implode("\r\n", gf_headers($body, $zugang)) . "\r\n", 'content' => $body, 'ignore_errors' => true],
    ]));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'body' => $antwort === false ? '' : (string) $antwort];
}

/** Antworttext bei HTTP 200, sonst null. */
function gf_fetch($method, $body, array $zugang)
{
    $r = gf_fetch_roh($method, $body, $zugang);

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

    return ['liste' => $liste, 'quellen' => $quellen];
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

/** Liniennamen einer Meldung: lines[] oder locations[].line, ohne Leerzeichen, eindeutig. */
function gf_meldung_linien(array $raw)
{
    $namen = [];
    foreach ($raw['lines'] ?? [] as $l) {
        $namen[] = is_array($l) ? ($l['name'] ?? '') : $l;
    }
    foreach ($raw['locations'] ?? [] as $loc) {
        if (is_array($loc) && is_array($loc['line'] ?? null)) {
            $namen[] = $loc['line']['name'] ?? '';
        }
    }
    $out = [];
    foreach ($namen as $n) {
        $n = str_replace(' ', '', nordbahn_text($n, 20));
        if ($n !== '' && !in_array($n, $out, true)) {
            $out[] = $n;
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
    ];
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
    $von = (new DateTime('@' . ($ts - 3600)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i:s.000P');
    $bis = (new DateTime('@' . ($ts + 7 * 86400)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i:s.000P');

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

/** Lagekarte fuer die S3: level, title, detail. Geplante Meldungen faerben nicht ein. */
function gf_status(array $abfahrten, array $meldungen, $now)
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
    $akut = [];
    $geplant = 0;
    foreach ($meldungen as $m) {
        if (empty($m['aktiv'])) {
            continue;
        }
        if (empty($m['geplant'])) {
            $akut[] = $m;
        } else {
            $geplant++;
        }
    }
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
    if ($akut) {
        return ['level' => 'alert', 'title' => 'S3 gestört', 'detail' => $akut[0]['titel'] . ($zahlen !== '' ? ' · ' . $zahlen : '')];
    }
    if (!$total) {
        return ['level' => 'ok', 'title' => 'S3: keine Abfahrten gemeldet', 'detail' => ''];
    }
    if ($betroffen / $total >= GF_ALERT_ANTEIL) {
        return ['level' => 'alert', 'title' => 'S3 gestört', 'detail' => $zahlen];
    }
    if ($betroffen) {
        return ['level' => 'warn', 'title' => 'S3 beeinträchtigt', 'detail' => $zahlen];
    }

    return ['level' => 'ok', 'title' => 'S3 fährt planmäßig', 'detail' => $geplant ? ($geplant === 1 ? '1 geplante HVV-Meldung' : $geplant . ' geplante HVV-Meldungen') : ''];
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
    $status = gf_status($abf['liste'], $meldungen ?? [], $now->getTimestamp());

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
    $cacheFile = gf_cache_file($stadt);
    $lockFile = gf_cache_file($stadt, 'lock');
    $jetzt = $now ? $now->getTimestamp() : time();
    $cached = nordbahn_read_json($cacheFile);
    if (is_array($cached) && isset($cached['fetched_at']) && $jetzt - strtotime($cached['fetched_at']) < GF_TTL) {
        return $cached;
    }
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen($lockFile, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = gf_build($fetch, $now, $zugang, $stadt);
        if ($result !== null) {
            nordbahn_write_json($cacheFile, $result);
            $cached = $result;
        } elseif (is_array($cached)) {
            $cached['ok'] = false;
        } else {
            $cached = gf_leer();
        }
        flock($lock, LOCK_UN);
    } elseif (!is_array($cached)) {
        $cached = gf_leer();
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
        'departureList Pinneberg gefiltert' => ['departureList', ['station' => ['name' => GF_PINNEBERG, 'type' => 'STATION'], 'time' => gf_zeit_param($now), 'maxList' => 40, 'maxTimeOffset' => GF_FENSTER_MIN, 'useRealtime' => true, 'filter' => [['serviceID' => GF_S3_SERVICE_ID]]]],
        'departureList Hamburg Hbf gefiltert' => ['departureList', ['station' => ['name' => 'Hamburg Hbf', 'type' => 'STATION'], 'time' => gf_zeit_param($now), 'maxList' => 40, 'maxTimeOffset' => GF_FENSTER_MIN, 'useRealtime' => true, 'filter' => [['serviceID' => GF_S3_SERVICE_ID]]]],
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

    return $out;
}
