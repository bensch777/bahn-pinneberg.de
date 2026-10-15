<?php
/**
 * Abfahrten fuer Pendler: vom Heimatbahnhof in die Stadt und aus der Stadt
 * zurueck.
 *
 * URL: https://nordbahn.bensch777.de/api/abfahrten?bhf=APD
 *      bhf ist die ID des Heimatbahnhofs aus NORDBAHN_BAHNHOEFE (store.php),
 *      ohne Angabe Prisdorf.
 *
 * Quelle ist der Stationsmonitor der Nordbahn (datnet, ETC Consult), derselbe
 * Dienst, den nordbahn.de/reiseplanung/stationsmonitor/ anzeigt. Je Bahnhof
 * liefert er ein XML mit den naechsten Abfahrten: Soll-Zeit, Prognose in
 * Minuten, Gleis, Ausfall, Ersatzverkehr, Anzahl der Fahrzeuge.
 *
 *   hin:    Abfahrten am Heimatbahnhof mit Ziel in Hamburg
 *   rueck:  Abfahrten in Hamburg Hbf (AH) und Hamburg-Altona (AA) Richtung
 *           Norden, die den Heimatbahnhof erreichen (Hbf-Zuege tragen ihren
 *           Halt am Dammtor (ADF) mit; ist der Zug am Hbf schon weg, steht er
 *           als eigene Zeile ab Dammtor): bis Elmshorn alle, auf
 *           dem Ast nach Itzehoe nur Zuege nach Itzehoe, auf dem nach Wrist
 *           nur Zuege nach Wrist. Faellt ein Zug ab Hbf aus und dieselbe
 *           Zugnummer startet ab Altona, wird das als Ersatz an die
 *           ausgefallene Zeile gehaengt (und umgekehrt); der Ersatz steht
 *           zusaetzlich als eigene Zeile in der Liste.
 *
 * Fuer ausgefallene, verspaetete und mit Zuglauf-Hinweis markierte Zuege holt
 * der Endpunkt zusaetzlich die Zugauskunft (?zug=Nummer): die Ursache und die
 * Halte mit Ausfall-Flag. Daraus entstehen Teilausfaelle ("endet heute in
 * Pinneberg", "beginnt erst in Pinneberg"). Hoechstens zehn Abfragen je
 * Aufbereitung, je Zug fuenf Minuten gecacht.
 *
 * Die Antwort wird je Bahnhof eine Minute lang gecacht, damit viele Browser
 * nicht viele Anfragen an die Nordbahn ausloesen. Ist der Monitor nicht
 * erreichbar, kommt der letzte bekannte Stand mit ok=false.
 */

require __DIR__ . '/store.php';

const ABF_API        = 'https://datnet-nbe.etc-consult.de/datnet-nbe/xml_v3';
const ABF_STADT      = ['AH' => 'Hbf', 'AA' => 'Altona']; // Startbahnhoefe fuer den Rueckweg
const ABF_DAMMTOR    = 'ADF';                        // Zwischenhalt der Hbf-Zuege, haengt an deren Zeile
const ABF_AUSFALL_GRACE = 5;                         // Minuten, die ein Ausfall nach seiner Zeit stehen bleibt
const ABF_STADT_IDS  = ['AH', 'AA', 'ADF'];          // Ziele, die "in die Stadt" bedeuten
const ABF_TTL        = 60;                           // Sekunden, die eine Antwort gueltig bleibt
const ABF_PER_DIR    = 8;                            // Abfahrten je Richtung in der Antwort
const ABF_TIMEOUT    = 6;                            // Sekunden je Anfrage
const ABF_ZUG_CACHE  = NORDBAHN_CACHE_DIR . '/zuege.json'; // Ursachen je Zugnummer
const ABF_ZUG_TTL    = 300;                                 // Sekunden, die eine Ursache gilt
const ABF_ZUG_MAX    = 16;                                  // Zugabfragen je Aufbereitung

/**
 * Erreicht ein Zug mit diesem Ziel den Heimatbahnhof? Bis Elmshorn ja, sofern
 * das Ziel dahinter liegt; auf den Aesten nur, wenn das Ziel auf demselben Ast
 * weiter draussen liegt. Unbekannte Ziele gelten fuer den Stamm als erreicht,
 * denn alle Nordbahn-Zuege aus Hamburg fahren mindestens bis Elmshorn.
 */
function abf_erreicht($zielId, $heimat)
{
    $bahnhoefe = NORDBAHN_BAHNHOEFE;
    $reihe = array_keys($bahnhoefe);
    $heimatAst = $bahnhoefe[$heimat]['ast'];
    if (!isset($bahnhoefe[$zielId])) {
        return $heimatAst === 'stamm';
    }
    $zielAst = $bahnhoefe[$zielId]['ast'];
    if ($heimatAst !== 'stamm' && $zielAst !== $heimatAst) {
        return false;
    }

    return array_search($heimat, $reihe, true) <= array_search($zielId, $reihe, true);
}

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

/**
 * Liest die Zugauskunft (?zug=Nummer): die Ursache und alle Halte mit Zeiten und
 * Ausfall-Flag. Ueber die Halte erkennt die Tafel Teilausfaelle, etwa wenn ein Zug
 * heute nur bis Pinneberg faehrt oder erst dort beginnt.
 */
function abf_parse_zug($xml)
{
    $out = ['ursache' => '', 'halte' => []];
    $doc = @simplexml_load_string($xml);
    if ($doc === false) {
        return $out;
    }
    $text = isset($doc->ursachetext) ? trim((string) $doc->ursachetext) : '';
    if ($text === '' && isset($doc->ursacheris)) {
        $text = trim((string) $doc->ursacheris);
    }
    foreach ($doc->halt as $halt) {
        if ($text === '') {
            $t = isset($halt->ursacheris) ? trim((string) $halt->ursacheris) : '';
            if ($t !== '') {
                $text = $t;
            }
        }
        $bhf = $halt->bhf ?? null;
        $out['halte'][] = [
            'id'      => $bhf !== null ? trim((string) $bhf['id']) : '',
            'name'    => $bhf !== null ? trim((string) $bhf) : '',
            'an'      => abf_text($halt, 'ankunft'),
            'ab'      => abf_text($halt, 'abfahrt'),
            'ausfall' => abf_bool($halt, 'ausfall'),
        ];
    }
    $out['ursache'] = $text;

    return $out;
}

/**
 * Teilausfall aus den Halten ableiten.
 *   hin:   Der Zug faehrt Richtung Hamburg, die Hamburger Halte entfallen. Ergebnis
 *          "endet" am letzten Halt, der noch bedient wird (z. B. Pinneberg). Endet er
 *          schon am Heimatbahnhof, ist er fuer den Pendler ein Ausfall.
 *   rueck: Der Startbahnhof der Zeile (Hbf/Dammtor/Altona) entfaellt, der Zug faehrt
 *          aber ab einem Halt vor dem Heimatbahnhof. Ergebnis "beginnt" dort. Startet
 *          er an einem anderen Hamburger Bahnhof, ist das der Ersatz-Fall, nicht dieser.
 */
function abf_teilausfall(array $row, array $halte, $heimat, $dir)
{
    if (!$halte) {
        return null;
    }
    $idx = function ($id) use ($halte) {
        foreach ($halte as $i => $h) {
            if ($h['id'] === $id) {
                return $i;
            }
        }

        return null;
    };
    $hi = $idx($heimat);
    if ($hi === null) {
        return null;
    }
    $n = count($halte);

    if ($dir === 'hin') {
        $letzter = null;
        $hamburgFaellt = false;
        for ($i = $hi + 1; $i < $n; $i++) {
            if (!$halte[$i]['ausfall']) {
                $letzter = $i;
            } elseif (in_array($halte[$i]['id'], ABF_STADT_IDS, true)) {
                $hamburgFaellt = true;
            }
        }
        if (!$hamburgFaellt) {
            // Variante zwei: die Hamburger Halte stehen gar nicht mehr im Zuglauf,
            // obwohl der Monitor ein Hamburger Ziel nennt. Dann endet der Zug am
            // letzten gelisteten Halt.
            $zielHamburg = in_array($row['ziel_id'] ?? '', ABF_STADT_IDS, true) || stripos($row['ziel'] ?? '', 'Hamburg') !== false;
            $hamburgGelistet = false;
            foreach ($halte as $h) {
                if (in_array($h['id'], ABF_STADT_IDS, true)) {
                    $hamburgGelistet = true;
                }
            }
            if (!$zielHamburg || $hamburgGelistet) {
                return null;
            }
        }
        if ($letzter === null) {
            return ['art' => 'endet', 'id' => $heimat, 'name' => $halte[$hi]['name'], 'zeit' => ''];
        }
        if (in_array($halte[$letzter]['id'], ABF_STADT_IDS, true)) {
            return null; // erreicht Hamburg doch noch, nur ein Zwischenhalt entfaellt
        }

        return ['art' => 'endet', 'id' => $halte[$letzter]['id'], 'name' => $halte[$letzter]['name'], 'zeit' => $halte[$letzter]['an'] ?: $halte[$letzter]['ab']];
    }

    $si = $row['ab_id'] !== null ? $idx($row['ab_id']) : null;
    if ($si === null || !$halte[$si]['ausfall']) {
        return null;
    }
    for ($i = $si + 1; $i < $hi; $i++) {
        if (!$halte[$i]['ausfall']) {
            if (in_array($halte[$i]['id'], ABF_STADT_IDS, true)) {
                return null;
            }

            return ['art' => 'beginnt', 'id' => $halte[$i]['id'], 'name' => $halte[$i]['name'], 'zeit' => $halte[$i]['ab'] ?: $halte[$i]['an']];
        }
    }

    return null;
}

/**
 * Zuglauf und Ursache je Zug nachladen. Der Stationsmonitor meldet einen Zug,
 * der unterwegs endet oder erst unterwegs beginnt, am Heimatbahnhof oft als
 * ganz normale Abfahrt (kein Ausfall, keine Verspaetung, kein Zuglaufhinweis).
 * Darum wird jeder Zug der Liste abgefragt; auffaellige Zuege (Ausfall,
 * Verspaetung, Zuglaufhinweis) zuerst, damit sie bei knappem Budget sicher
 * dran sind. Je Zug wird die Antwort fuenf Minuten gecacht, pro Aufbereitung
 * gibt es hoechstens ABF_ZUG_MAX Abfragen, damit ein schlechter Tag nicht zur
 * Lastspitze wird.
 */
function abf_add_zuginfo(array &$lists, $fetch, $heimat)
{
    $cache = nordbahn_read_json(ABF_ZUG_CACHE);
    if (!is_array($cache)) {
        $cache = [];
    }
    $now = time();
    $calls = 0;
    $changed = false;

    // Reihenfolge der Abfragen: erst die auffaelligen Zuege, dann der Rest
    $queue = [];
    foreach ($lists as $dir => $list) {
        foreach (array_slice($list, 0, ABF_PER_DIR, true) as $i => $row) {
            if ($row['zug'] === '') {
                continue;
            }
            $prio = ($row['ausfall'] || $row['verspaetung'] >= 3 || $row['zuglaufinfo']) ? 0 : 1;
            $queue[] = [$prio, $dir, $i];
        }
    }
    usort($queue, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    foreach ($queue as [$prio, $dir, $i]) {
        $row = &$lists[$dir][$i];
        $zug = $row['zug'];
        $entry = $cache[$zug] ?? null;
        if (!is_array($entry) || !isset($entry['halte']) || ($now - (int) ($entry['t'] ?? 0)) > ABF_ZUG_TTL) {
            if ($calls >= ABF_ZUG_MAX) {
                unset($row);
                continue;
            }
            $calls++;
            $xml = $fetch(abf_zug_url($zug));
            if ($xml === null) {
                unset($row);
                continue;
            }
            $entry = ['t' => $now] + abf_parse_zug($xml);
            $cache[$zug] = $entry;
            $changed = true;
        }
        $ursache = (string) ($entry['ursache'] ?? '');
        if ($ursache !== '' && stripos($row['hinweis'], $ursache) === false) {
            $row['hinweis'] = trim($ursache . ($row['hinweis'] !== '' ? '. ' . $row['hinweis'] : ''));
        }
        $teil = abf_teilausfall($row, $entry['halte'] ?? [], $heimat, $dir);
        if ($teil !== null && $teil['art'] === 'endet' && $teil['id'] === $heimat) {
            // Faehrt nicht ueber den Heimatbahnhof hinaus: fuer den Pendler ein Ausfall
            $row['ausfall'] = true;
            $row['ist'] = null;
            $row['hinweis'] = trim('Endet heute schon in ' . $teil['name'] . ($row['hinweis'] !== '' ? '. ' . $row['hinweis'] : ''));
            $teil = null;
        }
        $row['teil'] = $teil;
        unset($row);
    }

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
                    'gleis_plan'  => $rows[$j]['gleis_plan'],
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

        // Bei einem Gleiswechsel steht das Plangleis in <gleis> und das heutige in
        // <gleiswechsel> (z. B. "7"); ohne Wechsel ist das Feld leer oder "false".
        $gleisPlan = abf_text($row, 'gleis');
        $wechsel = abf_text($row, 'gleiswechsel');
        $gleisNeu = preg_match('/^[0-9]+[a-z]?$/i', $wechsel) && $wechsel !== $gleisPlan ? $wechsel : '';

        $hinweise = [];
        // "pünktlich, heute Gleis 7" oder "+ 4 Min": der Zeitteil ist schon in der
        // Prognose-Minute, der Rest kann ein Hinweis sein. Den Gleiswechsel zeigt
        // die Tafel ueber das Gleisfeld, nicht als Text.
        $prognose = abf_text($row, 'prognose');
        $rest = $ausfall ? '' : trim(preg_replace('/^(pünktlich|punktlich|\+?\s*\d+\s*min\.?)\s*[,;.]?\s*/iu', '', $prognose));
        if ($rest !== '' && !preg_match('/^heute\s+gleis/iu', $rest)) {
            $hinweise[] = $rest;
        }
        if (abf_bool($row, 'sev') || abf_bool($row, 'istBus')) {
            $hinweise[] = 'Ersatzverkehr mit Bus';
        }
        if ($gleisNeu === '' && $wechsel === 'true') {
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
            'gleis'       => $gleisNeu !== '' ? $gleisNeu : $gleisPlan,
            'gleis_plan'  => $gleisNeu !== '' ? $gleisPlan : null,
            'ausfall'     => $ausfall,
            'hinweis'     => implode('. ', array_unique($hinweise)),
            'wagen'       => abf_bool($row, 'istZug') && $wagen > 0 ? $wagen : null,
            'ab'          => null,
            'ab_id'       => null,
            'zuglaufinfo' => abf_bool($row, 'zuglaufinfo'), // Nordbahn hat einen Hinweis zum Zuglauf
            'teil'        => null,
            'ersatz'      => null,
            'ersatz_fuer' => null,
            'dammtor'     => null, // Halt am Dammtor, nur Zuege ab Hbf
        ];
    }

    return $out;
}

/** Holt die Tafeln und sortiert die Abfahrten nach hin und zurueck. */
function abf_build($fetch = 'abf_fetch', $heimat = NORDBAHN_HEIMAT_DEFAULT)
{
    $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $stand = '';
    $quellen = [];

    $hin = [];
    $xml = $fetch(abf_url($heimat));
    $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
    $quellen[$heimat] = $rows !== null;
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
            // Nur Zuege nach Norden, die am Heimatbahnhof vorbeikommen
            if (in_array($row['ziel_id'], ABF_STADT_IDS, true) || stripos($row['ziel'], 'Hamburg') !== false) {
                continue;
            }
            if (!abf_erreicht($row['ziel_id'], $heimat)) {
                continue;
            }
            $row['ab'] = $label;
            $row['ab_id'] = $id;
            $rueck[] = $row;
        }
    }

    // Dammtor liegt drei Minuten hinter dem Hbf auf derselben Fahrt. Die Zuege
    // haengen als Zwischenhalt an der Hbf-Zeile; ist ein Zug am Hbf schon weg,
    // steht er als eigene Zeile "ab Dammtor", solange er dort noch kommt.
    $dammtor = [];
    $xml = $fetch(abf_url(ABF_DAMMTOR));
    $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
    $quellen[ABF_DAMMTOR] = $rows !== null;
    foreach ($rows ?? [] as $row) {
        if (in_array($row['ziel_id'], ABF_STADT_IDS, true) || stripos($row['ziel'], 'Hamburg') !== false) {
            continue;
        }
        if ($row['zug'] === '' || !abf_erreicht($row['ziel_id'], $heimat)) {
            continue;
        }
        $dammtor[$row['zug']] = $row;
    }

    if (!$quellen[$heimat] && !in_array(true, $quellen, true)) {
        return null;
    }

    $sort = function ($a, $b) {
        return ($a['ist'] ?: $a['zeit']) <=> ($b['ist'] ?: $b['zeit']);
    };
    usort($hin, $sort);
    $rueck = abf_merge_ersatz($rueck);
    usort($rueck, $sort);

    // Vergangenes raus, bevor Ursachen geholt werden, sonst gehen Abfragen an alte
    // Zuege. Ausfaelle bleiben ein paar Minuten laenger stehen, damit der Bezug
    // zum Ersatzzug sichtbar bleibt.
    $cutoff = (clone $now)->modify('-1 minute');
    $cutoffAusfall = (clone $now)->modify('-' . ABF_AUSFALL_GRACE . ' minutes');
    $vorbei = function (array $row) use ($cutoff, $cutoffAusfall) {
        return ($row['ist'] ?: $row['zeit']) < ($row['ausfall'] ? $cutoffAusfall : $cutoff);
    };
    $upcoming = function (array $list) use ($vorbei) {
        return array_values(array_filter($list, function ($row) use ($vorbei) {
            return !$vorbei($row);
        }));
    };
    $rueck = $upcoming($rueck);
    $gesehen = [];
    foreach ($rueck as &$row) {
        if ($row['zug'] === '') {
            continue;
        }
        $gesehen[$row['zug']] = true;
        if ($row['ab'] === 'Hbf' && isset($dammtor[$row['zug']])) {
            $d = $dammtor[$row['zug']];
            $row['dammtor'] = [
                'zeit'        => $d['zeit'],
                'ist'         => $d['ist'],
                'verspaetung' => $d['verspaetung'],
                'gleis'       => $d['gleis'],
                'gleis_plan'  => $d['gleis_plan'],
                'ausfall'     => $d['ausfall'],
            ];
        }
    }
    unset($row);
    foreach ($upcoming(array_values($dammtor)) as $row) {
        if (isset($gesehen[$row['zug']]) || $row['ausfall']) {
            continue;
        }
        $row['ab'] = 'Dammtor';
        $row['ab_id'] = ABF_DAMMTOR;
        $rueck[] = $row;
    }
    usort($rueck, $sort);

    $lists = ['hin' => $upcoming($hin), 'rueck' => $rueck];
    abf_add_zuginfo($lists, $fetch, $heimat);
    $hin = $lists['hin'];
    $rueck = $lists['rueck'];

    $format = function (array $list) use ($vorbei) {
        $out = [];
        foreach ($list as $row) {
            if ($vorbei($row)) {
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
                    'gleis_plan'  => $row['ersatz']['gleis_plan'],
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
                'gleis_plan'  => $row['gleis_plan'],
                'ausfall'     => $row['ausfall'],
                'hinweis'     => $row['hinweis'],
                'wagen'       => $row['wagen'],
                'ab'          => $row['ab'],
                'ersatz'      => $ersatz,
                'teil'        => $row['teil'],
                'ersatz_fuer' => $row['ersatz_fuer']
                    ? ['ab' => $row['ersatz_fuer']['ab'], 'zeit' => $row['ersatz_fuer']['zeit']->format('c')]
                    : null,
                'dammtor'     => $row['dammtor'] ? [
                    'zeit'        => $row['dammtor']['zeit']->format('c'),
                    'ist'         => $row['dammtor']['ist'] ? $row['dammtor']['ist']->format('c') : null,
                    'verspaetung' => $row['dammtor']['verspaetung'],
                    'gleis'       => $row['dammtor']['gleis'],
                    'gleis_plan'  => $row['dammtor']['gleis_plan'],
                    'ausfall'     => $row['dammtor']['ausfall'],
                ] : null,
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
        'heimat'     => ['id' => $heimat, 'name' => NORDBAHN_BAHNHOEFE[$heimat]['name']],
        'quellen'    => $quellen,
        'richtungen' => ['hin' => $format($hin), 'rueck' => $format($rueck)],
    ];
}

// Auf der Kommandozeile laesst sich die Aufbereitung gegen lokale Dateien pruefen:
// php nordbahn/api/abfahrten.php [--bhf=APD] heimat.xml hbf.xml altona.xml dammtor.xml [zug.xml|zugverzeichnis/]
// Die fuenfte Angabe dient als Antwort fuer alle Zugabfragen (eine Datei) oder liefert je
// Zugnummer <verzeichnis>/<nummer>.xml; der Zug-Cache wird dabei nicht benutzt.
if (PHP_SAPI === 'cli') {
    $files = array_slice($argv, 1);
    $heimat = NORDBAHN_HEIMAT_DEFAULT;
    if (isset($files[0]) && strpos($files[0], '--bhf=') === 0) {
        $heimat = substr(array_shift($files), 6);
        if (!isset(NORDBAHN_BAHNHOEFE[$heimat])) {
            fwrite(STDERR, "Unbekannter Bahnhof: $heimat\n");
            exit(1);
        }
    }
    $zugFile = count($files) > 4 ? $files[4] : null;
    $index = 0;
    $result = abf_build(function ($url) use ($files, $zugFile, &$index) {
        if (strpos($url, '?zug=') !== false) {
            // Eine Datei fuer alle Zuege, oder ein Verzeichnis mit <zugnummer>.xml
            if ($zugFile !== null && is_dir($zugFile) && preg_match('/zug=(\d+)/', $url, $m)) {
                $f = $zugFile . '/' . $m[1] . '.xml';

                return is_readable($f) ? file_get_contents($f) : null;
            }

            return $zugFile !== null && is_readable($zugFile) ? file_get_contents($zugFile) : null;
        }
        $file = $files[$index++] ?? null;

        return $file !== null && is_readable($file) ? file_get_contents($file) : null;
    }, $heimat);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Robots-Tag: noindex');

$heimat = isset($_GET['bhf']) ? strtoupper(trim((string) $_GET['bhf'])) : NORDBAHN_HEIMAT_DEFAULT;
if (!isset(NORDBAHN_BAHNHOEFE[$heimat])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannter Bahnhof.', 'bahnhoefe' => array_keys(NORDBAHN_BAHNHOEFE)]);
    exit;
}
$cacheFile = NORDBAHN_CACHE_DIR . '/abfahrten-' . $heimat . '.json';
$lockFile  = NORDBAHN_CACHE_DIR . '/abfahrten-' . $heimat . '.lock';

$cached = nordbahn_read_json($cacheFile);
$fresh = is_array($cached) && isset($cached['fetched_at']) && (time() - strtotime($cached['fetched_at'])) < ABF_TTL;

if (!$fresh) {
    // Nur ein Prozess holt neue Daten, alle anderen liefern derweil den alten Stand.
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen($lockFile, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = abf_build('abf_fetch', $heimat);
        if ($result !== null) {
            nordbahn_write_json($cacheFile, $result);
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
