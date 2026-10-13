<?php
/**
 * Abfahrten fuer Pendler aus Prisdorf: hin in die Stadt und aus der Stadt
 * zurueck.
 *
 * URL: https://nordbahn.bensch777.de/api/abfahrten
 *
 * Quelle ist der Stationsmonitor der Nordbahn (datnet, ETC Consult), derselbe
 * Dienst, den nordbahn.de/reiseplanung/stationsmonitor/ anzeigt. Je Bahnhof
 * liefert er ein XML mit den naechsten Abfahrten: Soll-Zeit, Prognose in
 * Minuten, Gleis, Ausfall, Ersatzverkehr.
 *
 *   hin:    Abfahrten in Prisdorf (APD) mit Ziel in Hamburg
 *   rueck:  Abfahrten in Hamburg Hbf (AH) und Hamburg-Altona (AA) Richtung
 *           Norden; RB60, RB61 und RB71 halten alle in Prisdorf
 *
 * Die Antwort wird eine Minute lang gecacht, damit viele Browser nicht viele
 * Anfragen an die Nordbahn ausloesen. Ist der Monitor nicht erreichbar, kommt
 * der letzte bekannte Stand mit ok=false.
 */

require __DIR__ . '/store.php';

const ABF_API        = 'https://datnet-nbe.etc-consult.de/datnet-nbe/xml_v3';
const ABF_HEIMAT     = 'APD';                        // Prisdorf
const ABF_STADT      = ['AH' => 'Hbf', 'AA' => 'Altona']; // Startbahnhoefe fuer den Rueckweg
const ABF_STADT_IDS  = ['AH', 'AA', 'ADF'];          // Ziele, die "in die Stadt" bedeuten
const ABF_TTL        = 60;                           // Sekunden, die eine Antwort gueltig bleibt
const ABF_PER_DIR    = 8;                            // Abfahrten je Richtung in der Antwort
const ABF_TIMEOUT    = 6;                            // Sekunden je Anfrage
const ABF_CACHE_FILE = NORDBAHN_CACHE_DIR . '/abfahrten.json';
const ABF_LOCK_FILE  = NORDBAHN_CACHE_DIR . '/abfahrten.lock';

/** Holt eine URL als Text, oder null bei Fehler. */
function abf_fetch($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => ABF_TIMEOUT,
            CURLOPT_TIMEOUT        => ABF_TIMEOUT,
            CURLOPT_USERAGENT      => 'nordbahn.bensch777.de Monitor',
            CURLOPT_HTTPHEADER     => ['Accept: application/xml, text/xml'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $body !== false && $status === 200 ? $body : null;
    }

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => ABF_TIMEOUT, 'user_agent' => 'nordbahn.bensch777.de Monitor', 'header' => "Accept: application/xml\r\n"],
    ]));

    return $body === false ? null : $body;
}

/** URL der Abfahrtstafel eines Bahnhofs. */
function abf_url($stationId)
{
    return ABF_API . '?bhf=' . rawurlencode($stationId) . '&id_prod=&format=xml';
}

/**
 * Soll-Zeit einer Abfahrt als DateTime in deutscher Zeit. Der Zeitstempel im
 * XML ("2026-10-13T10:18Z") tragt zwar ein Z, meint aber Ortszeit; das Datum
 * daraus und die Sekunden aus zeitsek ergeben den Zeitpunkt.
 */
function abf_time($zeitstempel, $zeitsek, DateTime $now)
{
    $date = preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $zeitstempel, $m) ? $m[1] : $now->format('Y-m-d');
    $time = preg_match('/^(\d{2}:\d{2})(?::(\d{2}))?$/', (string) $zeitsek, $t) ? $t[1] . ':' . ($t[2] ?? '00') : '';
    if ($time === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, new DateTimeZone('Europe/Berlin'));

    return $dt instanceof DateTime ? $dt : null;
}

/** Text eines Kindelements, getrimmt. */
function abf_text(SimpleXMLElement $row, $name)
{
    return isset($row->{$name}) ? trim((string) $row->{$name}) : '';
}

function abf_bool(SimpleXMLElement $row, $name)
{
    return abf_text($row, $name) === 'true';
}

/**
 * Liest eine Abfahrtstafel und gibt die Abfahrten als Liste zurueck.
 *
 * @return array|null null, wenn das XML nicht lesbar ist
 */
function abf_parse($xml, DateTime $now, &$stand)
{
    $doc = @simplexml_load_string($xml);
    if ($doc === false || !isset($doc->abfahrt) && !isset($doc->name)) {
        return null;
    }
    if ($stand === '' && isset($doc->standsek)) {
        $stand = trim((string) $doc->standsek);
    }

    $out = [];
    foreach ($doc->abfahrt as $row) {
        $zeit = abf_time(abf_text($row, 'zeitstempel'), abf_text($row, 'zeitsek'), $now);
        if ($zeit === null) {
            continue;
        }
        $ausfall = abf_bool($row, 'ausfall');
        $delay = (int) abf_text($row, 'prognosemin');
        $ist = $ausfall ? null : (clone $zeit)->modify(($delay >= 0 ? '+' : '') . $delay . ' minutes');

        $hinweise = [];
        $prognose = abf_text($row, 'prognose');
        if (!$ausfall && $prognose !== '' && !preg_match('/^(pünktlich|punktlich|\+?\s*\d+\s*min)/iu', $prognose)) {
            $hinweise[] = $prognose;
        }
        if (abf_bool($row, 'sev') || abf_bool($row, 'istBus')) {
            $hinweise[] = 'Ersatzverkehr mit Bus';
        }
        if (abf_bool($row, 'gleiswechsel')) {
            $hinweise[] = 'Gleiswechsel';
        }
        foreach (['ursachetext', 'freitext'] as $name) {
            $text = abf_text($row, $name);
            if ($text !== '' && !in_array($text, $hinweise, true)) {
                $hinweise[] = $text;
            }
        }

        $ziel = $row->ziel ?? null;
        $out[] = [
            'zeit'        => $zeit,
            'ist'         => $ist,
            'verspaetung' => $ausfall ? 0 : $delay,
            'linie'       => abf_text($row, 'linie'),
            'zug'         => abf_text($row, 'zug'),
            'ziel'        => $ziel !== null ? trim((string) $ziel) : '',
            'ziel_id'     => $ziel !== null ? trim((string) $ziel['id']) : '',
            'gleis'       => abf_text($row, 'gleis'),
            'ausfall'     => $ausfall,
            'hinweis'     => implode('. ', array_unique($hinweise)),
            'ab'          => null,
        ];
    }

    return $out;
}

/** Holt die Tafeln und sortiert die Abfahrten nach hin und zurueck. */
function abf_build($fetch = 'abf_fetch')
{
    $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $stand = '';
    $quellen = [];

    $hin = [];
    $xml = $fetch(abf_url(ABF_HEIMAT));
    $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
    $quellen[ABF_HEIMAT] = $rows !== null;
    foreach ($rows ?? [] as $row) {
        if (in_array($row['ziel_id'], ABF_STADT_IDS, true) || stripos($row['ziel'], 'Hamburg') !== false) {
            $hin[] = $row;
        }
    }

    $rueck = [];
    foreach (ABF_STADT as $id => $label) {
        $xml = $fetch(abf_url($id));
        $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
        $quellen[$id] = $rows !== null;
        foreach ($rows ?? [] as $row) {
            // Alles, was nicht in Hamburg endet, faehrt ueber Prisdorf nach Norden
            if (in_array($row['ziel_id'], ABF_STADT_IDS, true) || stripos($row['ziel'], 'Hamburg') !== false) {
                continue;
            }
            $row['ab'] = $label;
            $rueck[] = $row;
        }
    }

    if (!$quellen[ABF_HEIMAT] && !in_array(true, $quellen, true)) {
        return null;
    }

    $sort = function ($a, $b) {
        return ($a['ist'] ?: $a['zeit']) <=> ($b['ist'] ?: $b['zeit']);
    };
    usort($hin, $sort);
    usort($rueck, $sort);

    $cutoff = (clone $now)->modify('-1 minute');
    $format = function (array $list) use ($cutoff) {
        $out = [];
        foreach ($list as $row) {
            if (($row['ist'] ?: $row['zeit']) < $cutoff) {
                continue;
            }
            $out[] = [
                'zeit'        => $row['zeit']->format('c'),
                'ist'         => $row['ist'] ? $row['ist']->format('c') : null,
                'verspaetung' => $row['verspaetung'],
                'linie'       => $row['linie'],
                'ziel'        => $row['ziel'],
                'gleis'       => $row['gleis'],
                'ausfall'     => $row['ausfall'],
                'hinweis'     => $row['hinweis'],
                'ab'          => $row['ab'],
            ];
            if (count($out) >= ABF_PER_DIR) {
                break;
            }
        }

        return $out;
    };

    return [
        'ok'         => true,
        'fetched_at' => $now->format('c'),
        'stand'      => $stand,
        'quellen'    => $quellen,
        'richtungen' => ['hin' => $format($hin), 'rueck' => $format($rueck)],
    ];
}

// Auf der Kommandozeile laesst sich die Aufbereitung gegen lokale Dateien pruefen:
// php nordbahn/api/abfahrten.php prisdorf.xml hbf.xml altona.xml
if (PHP_SAPI === 'cli') {
    $files = array_slice($argv, 1);
    $index = 0;
    $result = abf_build(function ($url) use ($files, &$index) {
        $file = $files[$index++] ?? null;

        return $file !== null && is_readable($file) ? file_get_contents($file) : null;
    });
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Robots-Tag: noindex');

$cached = nordbahn_read_json(ABF_CACHE_FILE);
$fresh = is_array($cached) && isset($cached['fetched_at']) && (time() - strtotime($cached['fetched_at'])) < ABF_TTL;

if (!$fresh) {
    // Nur ein Prozess holt neue Daten, alle anderen liefern derweil den alten Stand.
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen(ABF_LOCK_FILE, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = abf_build();
        if ($result !== null) {
            nordbahn_write_json(ABF_CACHE_FILE, $result);
            $cached = $result;
        } elseif (is_array($cached)) {
            $cached['ok'] = false;
        } else {
            $cached = ['ok' => false, 'fetched_at' => null, 'richtungen' => null];
        }
        flock($lock, LOCK_UN);
    } elseif (!is_array($cached)) {
        $cached = ['ok' => false, 'fetched_at' => null, 'richtungen' => null];
    }
    if ($lock !== false) {
        fclose($lock);
    }
}

echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
