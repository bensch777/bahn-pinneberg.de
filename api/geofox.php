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
 * und die Antwort von checkName. Das CLI dev/geofox.php zeigt die Rohdaten.
 */

require_once __DIR__ . '/store.php';

const GF_API_DEFAULT  = 'https://gti.geofox.de/gti/public';
const GF_VERSION      = 59;        // Protokollversion, per Konfiguration ueberschreibbar
const GF_TTL          = 60;        // so lange gilt ein Stand als frisch
const GF_STALE_TTL    = 180;       // bis dahin wird ein alter Stand sofort geliefert
const GF_TIMEOUT      = 6;
const GF_FENSTER_MIN  = 60;        // Abfahrten der naechsten 60 Minuten
const GF_PER_DIR      = 6;         // je Richtung fuer Statusregel und Anzeige
const GF_DELAY_MIN    = 5;         // ab dieser Verspaetung (Minuten) zaehlt ein Zug als betroffen
const GF_ALERT_ANTEIL = 0.25;      // ab diesem Anteil "S3 gestoert"
const GF_LINIE        = 'S3';
const GF_LINIEN       = ['S3', 'RB61', 'RB71'];
const GF_MELDUNGEN_MAX = 20;
const GF_STATIONEN_TTL = 86400;    // Stations-IDs einmal am Tag auffrischen
const GF_STATIONEN = [
    'hin'   => ['name' => 'Pinneberg',   'ziel' => '', 'ab' => 'Pinneberg'],
    'rueck' => ['name' => 'Hamburg Hbf', 'ziel' => 'Pinneberg', 'ab' => 'Hamburg Hbf'],
];
define('GF_CACHE', NORDBAHN_CACHE_DIR . '/geofox.json');
define('GF_LOCK', NORDBAHN_CACHE_DIR . '/geofox.lock');
define('GF_STATIONEN_CACHE', NORDBAHN_CACHE_DIR . '/geofox-stationen.json');

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

/** POST an die GTI; Antworttext bei HTTP 200, sonst null. */
function gf_fetch($method, $body, array $zugang)
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

        return $antwort !== false && $status === 200 ? $antwort : null;
    }
    $antwort = @file_get_contents($url, false, stream_context_create([
        'http' => ['method' => 'POST', 'timeout' => GF_TIMEOUT, 'user_agent' => nordbahn_site_host() . ' Monitor', 'header' => implode("\r\n", gf_headers($body, $zugang)) . "\r\n", 'content' => $body],
    ]));

    return $antwort === false ? null : $antwort;
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
 * Stationen fuer beide Richtungen, IDs aus dem Tagescache oder frisch per checkName.
 * Ohne ID geht die Anfrage mit Name und Typ raus (die GTI loest Namen selbst auf).
 */
function gf_stationen($fetch, $now = null, ?array $zugang = null)
{
    $now = $now ?? time();
    $cache = nordbahn_read_json(GF_STATIONEN_CACHE);
    $gueltig = is_array($cache) && isset($cache['stand']) && $now - (int) $cache['stand'] < GF_STATIONEN_TTL && !empty($cache['stationen']);
    $out = [];
    $neu = false;
    foreach (GF_STATIONEN as $key => $st) {
        $station = $gueltig && !empty($cache['stationen'][$key]['id']) ? $cache['stationen'][$key] : null;
        if ($station === null) {
            $station = gf_station_suchen($fetch, $st['name'], $zugang);
            $neu = $station !== null;
        }
        $out[$key] = $station ?? ['name' => $st['name'], 'type' => 'STATION'];
    }
    if ($neu) {
        nordbahn_write_json(GF_STATIONEN_CACHE, ['stand' => $now, 'stationen' => $out]);
    }

    return $out;
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
    $gleis = nordbahn_text($raw['platform'] ?? '', 20);

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

/**
 * S3-Abfahrten ab Pinneberg (Richtung Hamburg) und ab Hamburg Hbf (nur Richtung
 * Pinneberg) in den naechsten GF_FENSTER_MIN Minuten, hoechstens GF_PER_DIR je
 * Richtung. Null, wenn beide Anfragen scheitern.
 */
function gf_departures_s3($fetch, DateTime $now, ?array $zugang = null)
{
    $stationen = gf_stationen($fetch, $now->getTimestamp(), $zugang);
    $liste = [];
    $quellen = [];
    foreach (GF_STATIONEN as $key => $st) {
        $antwort = gf_request('departureList', [
            'station'       => $stationen[$key],
            'time'          => gf_zeit_param($now),
            'maxList'       => 20,
            'maxTimeOffset' => GF_FENSTER_MIN,
            'useRealtime'   => true,
        ], $fetch, $zugang);
        $quellen[$key] = $antwort !== null;
        if ($antwort === null) {
            continue;
        }
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

/**
 * HVV-Meldungen zu den Linien in $linien: aktive ungeplante zuerst, dann aktive
 * geplante, dann nach Beginn absteigend; hoechstens GF_MELDUNGEN_MAX. Null bei Fehler.
 */
function gf_announcements($fetch, DateTime $now, array $linien = GF_LINIEN, ?array $zugang = null)
{
    $ts = $now->getTimestamp();
    $antwort = gf_request('getAnnouncements', [
        'names'         => $linien,
        'timeRange'     => ['begin' => gmdate('Y-m-d\TH:i:s\Z', $ts - 3600), 'end' => gmdate('Y-m-d\TH:i:s\Z', $ts + 7 * 86400)],
        'full'          => true,
        'filterPlanned' => 'NO_FILTER',
    ], $fetch, $zugang);
    if ($antwort === null) {
        return null;
    }
    $out = [];
    foreach ($antwort['announcements'] ?? [] as $raw) {
        $m = gf_clean_meldung($raw, $ts);
        if ($m === null) {
            continue;
        }
        // Ohne Linienangabe lassen wir die Meldung stehen (die Anfrage war schon gefiltert)
        if ($m['linien'] && !array_intersect($m['linien'], $linien)) {
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

/* ---------- Aufbau und Cache ---------- */

/**
 * Kompletter Stand: ok, fetched_at, quellen {hin, rueck, meldungen}, s3 {level,
 * title, detail, abfahrten, meldungen}. Null, wenn keine Abfahrten zu holen waren.
 * Spaeter moeglich: Push bei neuer ungeplanter S3-Meldung (notify.php, id hvv:<id>:<version>).
 */
function gf_build($fetch = null, ?DateTime $now = null, ?array $zugang = null)
{
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $abf = gf_departures_s3($fetch, $now, $zugang);
    if ($abf === null) {
        return null;
    }
    $meldungen = gf_announcements($fetch, $now, GF_LINIEN, $zugang);
    $status = gf_status($abf['liste'], $meldungen ?? [], $now->getTimestamp());

    return [
        'ok'         => true,
        'fetched_at' => $now->format('c'),
        'quellen'    => $abf['quellen'] + ['meldungen' => $meldungen !== null],
        's3'         => $status + ['abfahrten' => $abf['liste'], 'meldungen' => $meldungen ?? []],
    ];
}

function gf_leer()
{
    return ['ok' => false, 'fetched_at' => null, 's3' => null];
}

/** Stand aus dem Cache, bei Bedarf erneuert (ein Prozess fragt, die anderen warten nicht). */
function gf_cached($fetch = null, ?DateTime $now = null, ?array $zugang = null)
{
    $zugang = $zugang ?? gf_zugang();
    if ($zugang === null && $fetch === null) {
        return gf_leer();
    }
    $jetzt = $now ? $now->getTimestamp() : time();
    $cached = nordbahn_read_json(GF_CACHE);
    if (is_array($cached) && isset($cached['fetched_at']) && $jetzt - strtotime($cached['fetched_at']) < GF_TTL) {
        return $cached;
    }
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen(GF_LOCK, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = gf_build($fetch, $now, $zugang);
        if ($result !== null) {
            nordbahn_write_json(GF_CACHE, $result);
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
