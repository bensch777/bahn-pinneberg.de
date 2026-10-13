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
 * Minuten, Gleis, Ausfall, Ersatzverkehr, Anzahl der Fahrzeuge.
 *
 *   hin:    Abfahrten in Prisdorf (APD) mit Ziel in Hamburg
 *   rueck:  Abfahrten in Hamburg Hbf (AH) und Hamburg-Altona (AA) Richtung
 *           Norden; RB60, RB61 und RB71 halten alle in Prisdorf. Faellt ein
 *           Zug ab Hbf aus und dieselbe Zugnummer startet ab Altona, wird das
 *           als Ersatz an die ausgefallene Zeile gehaengt (und umgekehrt);
 *           der Ersatz steht zusaetzlich als eigene Zeile in der Liste.
 *
 * Fuer ausgefallene und verspaetete Zuege holt der Endpunkt zusaetzlich die
 * Ursache vom Zug-Endpunkt (?zug=Nummer, Element ursachetext), hoechstens
 * sechs Abfragen je Aufbereitung, je Zug fuenf Minuten gecacht.
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
const ABF_ZUG_CACHE  = NORDBAHN_CACHE_DIR . '/zuege.json'; // Ursachen je Zugnummer
const ABF_ZUG_TTL    = 300;                                 // Sekunden, die eine Ursache gilt
const ABF_ZUG_MAX    = 6;                                   // Zugabfragen je Aufbereitung

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

/** URL der Zugdetails (Laufweg mit Prognosen und Ursache). */
function abf_zug_url($zug)
{
    return ABF_API . '?zug=' . rawurlencode($zug) . '&id_prod=&format=xml';
}

/** Ursache aus den Zugdetails, oder leer. */
function abf_parse_ursache($xml)
{
    $doc = @simplexml_load_string($xml);
    if ($doc === false) {
        return '';
    }
    $text = isset($doc->ursachetext) ? trim((string) $doc->ursachetext) : '';
    if ($text === '' && isset($doc->ursacheris)) {
        $text = trim((string) $doc->ursacheris);
    }
    if ($text === '') {
        foreach ($doc->halt as $halt) {
            $t = isset($halt->ursacheris) ? trim((string) $halt->ursacheris) : '';
            if ($t !== '') {
                $text = $t;
                break;
            }
        }
    }

    return $text;
}

/**
 * Ursachen fuer ausgefallene und verspaetete Zuege nachladen. Je Zug wird die
 * Antwort fuenf Minuten gecacht, pro Aufbereitung gibt es hoechstens
 * ABF_ZUG_MAX Abfragen, damit ein schlechter Tag nicht zur Lastspitze wird.
 */
function abf_add_ursachen(array &$lists, $fetch)
{
    $cache = nordbahn_read_json(ABF_ZUG_CACHE);
    if (!is_array($cache)) {
        $cache = [];
    }
    $now = time();
    $calls = 0;
    $changed = false;

    foreach ($lists as &$list) {
        $n = 0;
        foreach ($list as &$row) {
            if (++$n > ABF_PER_DIR) {
                break;
            }
            if ($row['zug'] === '' || (!$row['ausfall'] && $row['verspaetung'] < 3)) {
                continue;
            }
            $zug = $row['zug'];
            $entry = $cache[$zug] ?? null;
            if (!is_array($entry) || ($now - (int) ($entry['t'] ?? 0)) > ABF_ZUG_TTL) {
                if ($calls >= ABF_ZUG_MAX) {
                    continue;
                }
                $calls++;
                $xml = $fetch(abf_zug_url($zug));
                if ($xml === null) {
                    continue;
                }
                $entry = ['t' => $now, 'ursache' => abf_parse_ursache($xml)];
                $cache[$zug] = $entry;
                $changed = true;
            }
            $ursache = (string) ($entry['ursache'] ?? '');
            if ($ursache !== '' && stripos($row['hinweis'], $ursache) === false) {
                $row['hinweis'] = trim($ursache . ($row['hinweis'] !== '' ? '. ' . $row['hinweis'] : ''));
            }
        }
        unset($row);
    }
    unset($list);

    if ($changed) {
        foreach ($cache as $zug => $entry) {
            if (!is_array($entry) || ($now - (int) ($entry['t'] ?? 0)) > 6 * ABF_ZUG_TTL) {
                unset($cache[$zug]);
            }
        }
        nordbahn_write_json(ABF_ZUG_CACHE, $cache);
    }
}

/**
 * Faellt ein Zug an einem Hamburger Bahnhof aus und dieselbe Zugnummer faehrt
 * am anderen los, wird die fahrende Abfahrt als Ersatz an die ausgefallene
 * gehaengt. Sie bleibt trotzdem als eigene Zeile stehen, denn wer schon in
 * Altona steht, sucht sie dort in der Liste.
 */
function abf_merge_ersatz(array $rows)
{
    $byZug = [];
    foreach ($rows as $i => $row) {
        if ($row['zug'] !== '') {
            $byZug[$row['zug']][] = $i;
        }
    }
    foreach ($byZug as $indexes) {
        if (count($indexes) < 2) {
            continue;
        }
        foreach ($indexes as $i) {
            if (!$rows[$i]['ausfall']) {
                continue;
            }
            foreach ($indexes as $j) {
                if ($j === $i || $rows[$j]['ausfall'] || $rows[$j]['ab'] === $rows[$i]['ab']) {
                    continue;
                }
                // Der Ausfall bekommt den Verweis auf den Ersatz, der Ersatz bleibt als
                // eigene Zeile stehen und sagt, fuer welchen Zug er faehrt.
                $rows[$i]['ersatz'] = [
                    'ab'          => $rows[$j]['ab'],
                    'zeit'        => $rows[$j]['zeit'],
                    'ist'         => $rows[$j]['ist'],
                    'verspaetung' => $rows[$j]['verspaetung'],
                    'gleis'       => $rows[$j]['gleis'],
                ];
                $rows[$j]['ersatz_fuer'] = [
                    'ab'   => $rows[$i]['ab'],
                    'zeit' => $rows[$i]['zeit'],
                ];
                break;
            }
        }
    }

    return $rows;
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

        // Anzahl der Fahrzeuge im Zug; auf der Nordbahn-Tafel steht das als Wagenzahl
        $wagen = isset($row->fahrzeuge) ? count($row->fahrzeuge->fahrzeug) : 0;

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
            'wagen'       => abf_bool($row, 'istZug') && $wagen > 0 ? $wagen : null,
            'ab'          => null,
            'ersatz'      => null,
            'ersatz_fuer' => null,
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
    $rueck = abf_merge_ersatz($rueck);
    usort($rueck, $sort);

    // Vergangenes raus, bevor Ursachen geholt werden, sonst gehen Abfragen an alte Zuege
    $cutoff = (clone $now)->modify('-1 minute');
    $upcoming = function (array $list) use ($cutoff) {
        return array_values(array_filter($list, function ($row) use ($cutoff) {
            return ($row['ist'] ?: $row['zeit']) >= $cutoff;
        }));
    };
    $lists = ['hin' => $upcoming($hin), 'rueck' => $upcoming($rueck)];
    abf_add_ursachen($lists, $fetch);
    $hin = $lists['hin'];
    $rueck = $lists['rueck'];

    $format = function (array $list) use ($cutoff) {
        $out = [];
        foreach ($list as $row) {
            if (($row['ist'] ?: $row['zeit']) < $cutoff) {
                continue;
            }
            $ersatz = null;
            if ($row['ersatz']) {
                $ersatz = [
                    'ab'          => $row['ersatz']['ab'],
                    'zeit'        => $row['ersatz']['zeit']->format('c'),
                    'ist'         => $row['ersatz']['ist'] ? $row['ersatz']['ist']->format('c') : null,
                    'verspaetung' => $row['ersatz']['verspaetung'],
                    'gleis'       => $row['ersatz']['gleis'],
                ];
            }
            $out[] = [
                'zug'         => $row['zug'],
                'zeit'        => $row['zeit']->format('c'),
                'ist'         => $row['ist'] ? $row['ist']->format('c') : null,
                'verspaetung' => $row['verspaetung'],
                'linie'       => $row['linie'],
                'ziel'        => $row['ziel'],
                'gleis'       => $row['gleis'],
                'ausfall'     => $row['ausfall'],
                'hinweis'     => $row['hinweis'],
                'wagen'       => $row['wagen'],
                'ab'          => $row['ab'],
                'ersatz'      => $ersatz,
                'ersatz_fuer' => $row['ersatz_fuer']
                    ? ['ab' => $row['ersatz_fuer']['ab'], 'zeit' => $row['ersatz_fuer']['zeit']->format('c')]
                    : null,
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
// php nordbahn/api/abfahrten.php prisdorf.xml hbf.xml altona.xml [zug.xml]
// Die vierte Datei dient als Antwort fuer alle Zugabfragen; der Zug-Cache wird dabei nicht benutzt.
if (PHP_SAPI === 'cli') {
    $files = array_slice($argv, 1);
    $zugFile = count($files) > 3 ? $files[3] : null;
    $index = 0;
    $result = abf_build(function ($url) use ($files, $zugFile, &$index) {
        if (strpos($url, '?zug=') !== false) {
            return $zugFile !== null && is_readable($zugFile) ? file_get_contents($zugFile) : null;
        }
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
