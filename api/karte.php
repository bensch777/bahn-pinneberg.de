<?php
/**
 * Live-Karte: wo die Zuege der Nordbahn gerade sind.
 *
 * URL: /api/karte
 *
 * Es gibt keine GPS-Ortung. Die Positionen sind berechnet: der Stationsmonitor der
 * Nordbahn (datnet, derselbe Dienst wie bei api/abfahrten) liefert je Bahnhof die
 * naechsten Abfahrten und je Zug den Laufweg mit Soll-Zeiten und Prognose je Halt.
 * Aus den Tafeln aller 15 Bahnhoefe (Hamburg Hbf, Dammtor, Altona und die zwoelf
 * Nordbahn-Halte) kommen die Zugnummern, die in den naechsten KA_VORLAUF Minuten
 * irgendwo abfahren, also unterwegs sind oder gleich starten. Fuer jeden dieser
 * Zuege holt der Endpunkt den Laufweg und rechnet aus den Ist-Zeiten (Soll plus
 * Prognose), zwischen welchen Halten der Zug gerade ist und wie weit; die
 * Koordinate dazu kommt aus dem Streckennetz in karte/strecke.json.
 *
 * Ein Zug, der seinen letzten Zwischenhalt verlassen hat, steht auf keiner Tafel
 * mehr (die Tafeln zeigen Abfahrten, keine Ankuenfte). Er bleibt deshalb aus dem
 * vorigen Stand bekannt und wird weiter abgefragt, bis er am Ziel ist.
 *
 * Antwort: ok, fetched_at, stand, quellen (je Tafel), zuege[] mit zug, linie, von,
 * nach, richtung (nord, hamburg), status (wartet, halt, unterwegs, ziel),
 * verspaetung (Minuten am naechsten bzw. aktuellen Halt), hinweis, wagen, lage
 * {von, nach, bei, anteil, lat, lon, text} und halte[] (id, name, an, ab, an_ist,
 * ab_ist, verspaetung, ausfall, gleis). Ein Stand gilt KA_TTL Sekunden; bis
 * KA_STALE_TTL wird der alte Stand sofort geliefert und im Hintergrund erneuert.
 */

require_once __DIR__ . '/store.php';
if (!defined('NORDBAHN_ABF_LIB')) {
    define('NORDBAHN_ABF_LIB', true);
}
require_once __DIR__ . '/abfahrten.php';

const KA_TTL        = 60;    // Sekunden, die ein Stand frisch ist
const KA_STALE_TTL  = 180;   // bis dahin wird ein alter Stand sofort geliefert
const KA_VORLAUF    = 20;    // Minuten vor der Abfahrt, ab denen ein Zug auf der Karte steht
const KA_NACHLAUF   = 3;     // Minuten nach der Ankunft am Ziel, die er noch stehen bleibt
const KA_ZUG_MAX    = 30;    // Laufweg-Abfragen je Aufbereitung
// Tafeln, die abgefragt werden, in Streckenreihenfolge von Hamburg nach Norden
// (die Reihenfolge bestimmt auch die Fahrtrichtung "nord" oder "hamburg")
const KA_STATIONEN  = ['AH', 'ADF', 'AA', 'AP', 'APD', 'ATM', 'AEL', 'AHZH', 'AGST', 'AKM', 'AKHD', 'AIZ', 'AHOT', 'ADH', 'AWST'];
define('KA_CACHE', NORDBAHN_CACHE_DIR . '/karte.json');
define('KA_LOCK', NORDBAHN_CACHE_DIR . '/karte.lock');
define('KA_STRECKE', __DIR__ . '/../karte/strecke.json');

/* ---------- Streckennetz ---------- */

/** Streckennetz aus karte/strecke.json: stationen, kanten, ansichten, linien. */
function ka_strecke()
{
    static $strecke = null;
    if ($strecke === null) {
        $strecke = nordbahn_read_json(KA_STRECKE) ?? ['stationen' => [], 'kanten' => []];
    }

    return $strecke;
}

/** Koordinate [lat, lon] einer Station oder null. */
function ka_station($id)
{
    $s = ka_strecke()['stationen'][$id] ?? null;

    return is_array($s) && isset($s['lat'], $s['lon']) ? [(float) $s['lat'], (float) $s['lon']] : null;
}

/** Nachbarn je Knoten mit dem Linienzug dorthin (beide Richtungen), einmal aufgebaut. */
function ka_graph()
{
    static $graph = null;
    if ($graph !== null) {
        return $graph;
    }
    $graph = [];
    foreach (ka_strecke()['kanten'] as $k) {
        $von = ka_station($k['von'] ?? '');
        $bis = ka_station($k['bis'] ?? '');
        if ($von === null || $bis === null) {
            continue;
        }
        $zwischen = [];
        foreach (is_array($k['zwischen'] ?? null) ? $k['zwischen'] : [] as $p) {
            if (is_array($p) && count($p) >= 2) {
                $zwischen[] = [(float) $p[0], (float) $p[1]];
            }
        }
        $hin = array_merge([$von], $zwischen, [$bis]);
        $graph[$k['von']][$k['bis']] = $hin;
        $graph[$k['bis']][$k['von']] = array_reverse($hin);
    }

    return $graph;
}

/**
 * Linienzug von Station zu Station durch das Netz (kuerzester Weg nach Kanten,
 * das Netz ist ein Baum): Liste von [lat, lon] inklusive beider Enden. Null,
 * wenn eine Station unbekannt ist oder kein Weg existiert.
 */
function ka_pfad($von, $bis)
{
    static $cache = [];
    $key = $von . '>' . $bis;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $graph = ka_graph();
    if (!isset($graph[$von]) || !isset($graph[$bis])) {
        return $cache[$key] = null;
    }
    // Breitensuche ueber die Knoten
    $vorgaenger = [$von => null];
    $queue = [$von];
    while ($queue && !isset($vorgaenger[$bis])) {
        $n = array_shift($queue);
        foreach ($graph[$n] as $nachbar => $punkte) {
            if (!array_key_exists($nachbar, $vorgaenger)) {
                $vorgaenger[$nachbar] = $n;
                $queue[] = $nachbar;
            }
        }
    }
    if (!isset($vorgaenger[$bis])) {
        return $cache[$key] = null;
    }
    $knoten = [];
    for ($n = $bis; $n !== null; $n = $vorgaenger[$n]) {
        array_unshift($knoten, $n);
    }
    $pfad = [];
    for ($i = 1; $i < count($knoten); $i++) {
        $teil = $graph[$knoten[$i - 1]][$knoten[$i]];
        $pfad = array_merge($pfad, $pfad ? array_slice($teil, 1) : $teil);
    }

    return $cache[$key] = $pfad;
}

/** Entfernung zweier Koordinaten in Metern (Haversine). */
function ka_dist(array $a, array $b)
{
    $r = 6371000;
    $la1 = deg2rad($a[0]);
    $la2 = deg2rad($b[0]);
    $dla = $la2 - $la1;
    $dlo = deg2rad($b[1] - $a[1]);
    $h = sin($dla / 2) ** 2 + cos($la1) * cos($la2) * sin($dlo / 2) ** 2;

    return 2 * $r * asin(min(1, sqrt($h)));
}

/** Punkt [lat, lon] bei Anteil 0..1 der Laenge eines Linienzugs. */
function ka_punkt_auf_pfad(array $pfad, $anteil)
{
    $n = count($pfad);
    if ($n === 0) {
        return null;
    }
    if ($n === 1) {
        return $pfad[0];
    }
    $laengen = [];
    $gesamt = 0.0;
    for ($i = 1; $i < $n; $i++) {
        $laengen[$i] = ka_dist($pfad[$i - 1], $pfad[$i]);
        $gesamt += $laengen[$i];
    }
    $anteil = max(0.0, min(1.0, (float) $anteil));
    if ($gesamt <= 0) {
        return $pfad[0];
    }
    $ziel = $anteil * $gesamt;
    $gelaufen = 0.0;
    for ($i = 1; $i < $n; $i++) {
        if ($gelaufen + $laengen[$i] >= $ziel || $i === $n - 1) {
            $f = $laengen[$i] > 0 ? ($ziel - $gelaufen) / $laengen[$i] : 0;
            $f = max(0.0, min(1.0, $f));

            return [
                round($pfad[$i - 1][0] + ($pfad[$i][0] - $pfad[$i - 1][0]) * $f, 6),
                round($pfad[$i - 1][1] + ($pfad[$i][1] - $pfad[$i - 1][1]) * $f, 6),
            ];
        }
        $gelaufen += $laengen[$i];
    }

    return $pfad[$n - 1];
}

/* ---------- Laufweg ---------- */

/**
 * Zeit eines Halts als DateTime: Datum aus <datum> (dd.mm.yyyy), Uhrzeit aus
 * ankunftsek/abfahrtsek. Wie bei den Tafeln kann das Datum nach Mitternacht
 * daneben liegen; mehr als zwoelf Stunden Abstand zu jetzt heisst anderer Tag.
 */
function ka_halt_zeit($datum, $uhr, DateTime $now)
{
    if (!preg_match('/^(\d{2}:\d{2})(?::(\d{2}))?$/', (string) $uhr, $t)) {
        return null;
    }
    $date = preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', (string) $datum, $d) ? $d[3] . '-' . $d[2] . '-' . $d[1] : $now->format('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $t[1] . ':' . ($t[2] ?? '00'), new DateTimeZone('Europe/Berlin'));
    if (!$dt instanceof DateTime) {
        return null;
    }
    $diff = $dt->getTimestamp() - $now->getTimestamp();
    if ($diff < -12 * 3600) {
        $dt->modify('+1 day');
    } elseif ($diff > 12 * 3600) {
        $dt->modify('-1 day');
    }

    return $dt;
}

/**
 * Liest die Zugauskunft (?zug=Nummer) fuer die Karte: Zugnummer, Linie, Ursache
 * und alle Halte mit Soll- und Ist-Zeiten (Soll plus Prognose in Minuten).
 * Null, wenn das XML nicht lesbar ist.
 */
function ka_parse_zug($xml, DateTime $now)
{
    $doc = @simplexml_load_string((string) $xml);
    if ($doc === false || !isset($doc->halt)) {
        return null;
    }
    $ursache = isset($doc->ursachetext) ? trim((string) $doc->ursachetext) : '';
    if ($ursache === '' && isset($doc->ursacheris)) {
        $ursache = trim((string) $doc->ursacheris);
    }
    $zuglauf = isset($doc->zuglaufinfotext) ? trim(preg_replace('/\s+/', ' ', (string) $doc->zuglaufinfotext)) : '';
    $entfallen = abf_entfallene_halte($zuglauf);
    $halte = [];
    foreach ($doc->halt as $halt) {
        $bhf = $halt->bhf ?? null;
        $name = $bhf !== null ? trim((string) $bhf) : '';
        $datum = abf_text($halt, 'datum');
        $an = ka_halt_zeit($datum, abf_text($halt, 'ankunftsek') ?: abf_text($halt, 'ankunft'), $now);
        $ab = ka_halt_zeit($datum, abf_text($halt, 'abfahrtsek') ?: abf_text($halt, 'abfahrt'), $now);
        $anMin = (int) abf_text($halt, 'anprognosemin');
        $abMin = (int) abf_text($halt, 'abprognosemin');
        $ausfall = abf_bool($halt, 'ausfall') || in_array(mb_strtolower($name), $entfallen, true);
        // Am Endhalt steht bei "haelt nicht in …" die Ankunft trotzdem als "puenktlich":
        // der Zug kommt an und wendet dort (siehe abf_parse_zug)
        $anProg = trim(abf_text($halt, 'anprognose'));
        $anOk = $anProg !== '' && stripos($anProg, 'entf') === false;
        if ($ursache === '') {
            $u = trim(abf_text($halt, 'ursacheris'));
            if ($u !== '') {
                $ursache = $u;
            }
        }
        $halte[] = [
            'id'      => $bhf !== null ? trim((string) $bhf['id']) : '',
            'name'    => $name,
            'an'      => $an,
            'ab'      => $ab,
            'an_ist'  => $an ? (clone $an)->modify(($anMin >= 0 ? '+' : '') . $anMin . ' minutes') : null,
            'ab_ist'  => $ab ? (clone $ab)->modify(($abMin >= 0 ? '+' : '') . $abMin . ' minutes') : null,
            'an_min'  => $anMin,
            'ab_min'  => $abMin,
            'ausfall' => $ausfall,
            // Haelt der Zug hier nicht, kommt aber laut Prognose an (Endhalt), zaehlt er als bedient
            'bedient' => !$ausfall || ($anOk && $an !== null),
            'gleis'   => abf_text($halt, 'gleis'),
        ];
    }

    return [
        'zug'     => isset($doc->zug) ? trim((string) $doc->zug) : '',
        'linie'   => isset($doc->linie) ? str_replace(' ', '', trim((string) $doc->linie)) : '',
        'bus'     => abf_bool($doc, 'istBus'),
        'ursache' => $ursache,
        'zuglauf' => $zuglauf,
        'halte'   => $halte,
    ];
}

/** Halte aus dem Cache (ISO-Zeiten) zurueck in die interne Form mit DateTime. */
function ka_halte_lesen(array $halte)
{
    $out = [];
    foreach ($halte as $h) {
        if (!is_array($h) || empty($h['id'])) {
            continue;
        }
        $zeit = function ($iso) {
            return is_string($iso) && $iso !== '' ? new DateTime($iso) : null;
        };
        $out[] = [
            'id'      => (string) $h['id'],
            'name'    => (string) ($h['name'] ?? ''),
            'an'      => $zeit($h['an'] ?? null),
            'ab'      => $zeit($h['ab'] ?? null),
            'an_ist'  => $zeit($h['an_ist'] ?? null),
            'ab_ist'  => $zeit($h['ab_ist'] ?? null),
            'an_min'  => (int) ($h['verspaetung'] ?? 0),
            'ab_min'  => (int) ($h['verspaetung'] ?? 0),
            'ausfall' => !empty($h['ausfall']),
            'bedient' => !empty($h['bedient']),
            'gleis'   => (string) ($h['gleis'] ?? ''),
        ];
    }

    return $out;
}

/* ---------- Lage ---------- */

/**
 * Wo ist der Zug gerade? Aus den bedienten Halten mit bekannter Koordinate und
 * den Ist-Zeiten:
 *   wartet     vor der ersten Abfahrt (hoechstens KA_VORLAUF Minuten davor)
 *   halt       zwischen Ankunft und Abfahrt an einem Halt
 *   unterwegs  zwischen zwei Halten, anteil 0..1 nach Zeit
 *   ziel       nach der letzten Ankunft (hoechstens KA_NACHLAUF Minuten danach)
 * Null, wenn der Zug nicht auf die Karte gehoert (zu frueh, vorbei, keine Daten).
 * Rueckgabe: status, von/nach/bei (Index in $halte), anteil, verspaetung.
 */
function ka_lage(array $halte, DateTime $now)
{
    $bedient = [];
    foreach ($halte as $i => $h) {
        if (!empty($h['bedient']) && ka_station($h['id']) !== null && ($h['an_ist'] !== null || $h['ab_ist'] !== null)) {
            $bedient[] = $i;
        }
    }
    if (count($bedient) < 2) {
        return null;
    }
    $ts = $now->getTimestamp();
    $dep = function ($i) use ($halte) {
        return ($halte[$i]['ab_ist'] ?: $halte[$i]['an_ist'])->getTimestamp();
    };
    $arr = function ($i) use ($halte) {
        return ($halte[$i]['an_ist'] ?: $halte[$i]['ab_ist'])->getTimestamp();
    };
    $erster = $bedient[0];
    $letzter = $bedient[count($bedient) - 1];
    if ($ts < $dep($erster)) {
        if ($dep($erster) - $ts > KA_VORLAUF * 60) {
            return null;
        }

        return ['status' => 'wartet', 'bei' => $erster, 'von' => null, 'nach' => null, 'anteil' => null, 'verspaetung' => $halte[$erster]['ab_min']];
    }
    if ($ts >= $arr($letzter)) {
        if ($ts - $arr($letzter) > KA_NACHLAUF * 60) {
            return null;
        }

        return ['status' => 'ziel', 'bei' => $letzter, 'von' => null, 'nach' => null, 'anteil' => null, 'verspaetung' => $halte[$letzter]['an_min']];
    }
    for ($k = 0; $k < count($bedient) - 1; $k++) {
        $i = $bedient[$k];
        $j = $bedient[$k + 1];
        if ($ts < $dep($i)) {
            // Angekommen, noch nicht abgefahren (oder die Zeiten widersprechen sich)
            return ['status' => 'halt', 'bei' => $i, 'von' => null, 'nach' => null, 'anteil' => null, 'verspaetung' => $halte[$i]['ab_min']];
        }
        if ($ts < $arr($j)) {
            $dauer = $arr($j) - $dep($i);
            $anteil = $dauer > 0 ? ($ts - $dep($i)) / $dauer : 1.0;

            return ['status' => 'unterwegs', 'bei' => null, 'von' => $i, 'nach' => $j, 'anteil' => round(max(0.0, min(1.0, $anteil)), 4), 'verspaetung' => $halte[$j]['an_min']];
        }
    }

    return ['status' => 'ziel', 'bei' => $letzter, 'von' => null, 'nach' => null, 'anteil' => null, 'verspaetung' => $halte[$letzter]['an_min']];
}

/** "10:18" in deutscher Zeit. */
function ka_uhr(?DateTime $dt)
{
    return $dt ? (clone $dt)->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i') : '';
}

/** Lesbarer Satz zur Lage, fuer die Liste ohne JavaScript und fuer die API. */
function ka_lage_text(array $lage, array $halte)
{
    switch ($lage['status']) {
        case 'wartet':
            $h = $halte[$lage['bei']];
            $gleis = $h['gleis'] !== '' ? ', Gleis ' . $h['gleis'] : '';

            return 'Steht in ' . $h['name'] . ', Abfahrt ' . ka_uhr($h['ab_ist'] ?: $h['an_ist']) . $gleis;
        case 'halt':
            $h = $halte[$lage['bei']];

            return 'Hält in ' . $h['name'] . ($h['ab_ist'] ? ', weiter ' . ka_uhr($h['ab_ist']) : '');
        case 'ziel':
            $h = $halte[$lage['bei']];

            return 'Angekommen in ' . $h['name'] . ' um ' . ka_uhr($h['an_ist'] ?: $h['ab_ist']);
        default:
            $v = $halte[$lage['von']];
            $n = $halte[$lage['nach']];

            return 'Zwischen ' . $v['name'] . ' und ' . $n['name'] . ', ' . $n['name'] . ' ' . ka_uhr($n['an_ist'] ?: $n['ab_ist']);
    }
}

/** Fahrtrichtung aus dem ersten und letzten bedienten Halt: nord oder hamburg. */
function ka_richtung(array $halte)
{
    $reihe = array_flip(KA_STATIONEN);
    $erster = null;
    $letzter = null;
    foreach ($halte as $h) {
        if (empty($h['bedient']) || !isset($reihe[$h['id']])) {
            continue;
        }
        if ($erster === null) {
            $erster = $reihe[$h['id']];
        }
        $letzter = $reihe[$h['id']];
    }

    return $erster !== null && $letzter !== null && $letzter < $erster ? 'hamburg' : 'nord';
}

/**
 * Ein Zug fuer die Antwort: Stammdaten, Lage mit Koordinate und die Halte mit
 * ISO-Zeiten. Null, wenn der Zug nicht auf die Karte gehoert.
 */
function ka_zug_bauen(array $detail, DateTime $now, array $tafel = [])
{
    $halte = $detail['halte'];
    $lage = ka_lage($halte, $now);
    if ($lage === null) {
        return null;
    }
    $station = function ($i) use ($halte) {
        if ($i === null) {
            return null;
        }
        $h = $halte[$i];

        return ['id' => $h['id'], 'name' => $h['name'], 'an' => $h['an_ist'] ? $h['an_ist']->format('c') : null, 'ab' => $h['ab_ist'] ? $h['ab_ist']->format('c') : null, 'gleis' => $h['gleis']];
    };
    if ($lage['status'] === 'unterwegs') {
        $pfad = ka_pfad($halte[$lage['von']]['id'], $halte[$lage['nach']]['id']);
        $punkt = $pfad ? ka_punkt_auf_pfad($pfad, $lage['anteil']) : ka_station($halte[$lage['von']]['id']);
    } else {
        $punkt = ka_station($halte[$lage['bei']]['id']);
    }
    $erster = null;
    $letzter = null;
    $ende = null;
    $halteAus = [];
    foreach ($halte as $h) {
        if (!empty($h['bedient'])) {
            if ($erster === null) {
                $erster = $h['name'];
            }
            $letzter = $h['name'];
            $ende = $h['an_ist'] ?: ($h['ab_ist'] ?: $ende);
        }
        $halteAus[] = [
            'id'          => $h['id'],
            'name'        => $h['name'],
            'an'          => $h['an'] ? $h['an']->format('c') : null,
            'ab'          => $h['ab'] ? $h['ab']->format('c') : null,
            'an_ist'      => $h['an_ist'] ? $h['an_ist']->format('c') : null,
            'ab_ist'      => $h['ab_ist'] ? $h['ab_ist']->format('c') : null,
            'verspaetung' => $h['ab_ist'] ? $h['ab_min'] : $h['an_min'],
            'ausfall'     => $h['ausfall'],
            'bedient'     => $h['bedient'],
            'gleis'       => $h['gleis'],
        ];
    }
    $hinweis = $detail['ursache'];
    $zuglauf = abf_zuglauf_hinweis($detail['zuglauf'] ?? '');
    if ($zuglauf !== '' && stripos($hinweis, $zuglauf) === false) {
        $hinweis = trim($zuglauf . ($hinweis !== '' ? '. ' . $hinweis : ''));
    }

    return [
        'zug'         => $detail['zug'],
        'linie'       => $detail['linie'],
        'von'         => $erster,
        'nach'        => $letzter,
        'richtung'    => ka_richtung($halte),
        'status'      => $lage['status'],
        'verspaetung' => (int) $lage['verspaetung'],
        'hinweis'     => $hinweis,
        'wagen'       => $tafel['wagen'] ?? null,
        'lage'        => [
            'von'    => $station($lage['von']),
            'nach'   => $station($lage['nach']),
            'bei'    => $station($lage['bei']),
            'anteil' => $lage['anteil'],
            'lat'    => $punkt ? $punkt[0] : null,
            'lon'    => $punkt ? $punkt[1] : null,
            'text'   => ka_lage_text($lage, $halte),
        ],
        'halte'       => $halteAus,
        'ende'        => $ende ? $ende->format('c') : null,
    ];
}

/* ---------- Aufbau ---------- */

/**
 * Kompletter Stand: Tafeln aller Bahnhoefe lesen, Kandidaten bestimmen, Laufwege
 * holen, Lage rechnen. $alt ist der vorige Stand (Zuege, die auf keiner Tafel
 * mehr stehen, bleiben daraus bekannt; scheitert eine Laufweg-Abfrage, gilt der
 * alte Laufweg weiter). Null, wenn keine Tafel zu lesen war.
 */
function ka_build($fetch = 'abf_fetch', ?DateTime $now = null, ?array $alt = null)
{
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $ts = $now->getTimestamp();
    $stand = '';
    $quellen = [];
    $tafeln = abf_fetch_many(array_map('abf_url', KA_STATIONEN), $fetch);

    // Kandidaten: Zugnummern mit der fruehesten Abfahrt auf irgendeiner Tafel
    $kandidaten = [];
    foreach (KA_STATIONEN as $id) {
        $xml = $tafeln[abf_url($id)] ?? null;
        $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
        $quellen[$id] = $rows !== null;
        foreach ($rows ?? [] as $row) {
            if ($row['zug'] === '' || $row['bus'] || !preg_match('/^RB\s*\d/i', $row['linie'])) {
                continue;
            }
            $t = ($row['ist'] ?: $row['zeit'])->getTimestamp();
            if (!isset($kandidaten[$row['zug']]) || $t < $kandidaten[$row['zug']]['t']) {
                $kandidaten[$row['zug']] = ['t' => $t, 'wagen' => $row['wagen']];
            }
        }
    }
    if (!in_array(true, $quellen, true)) {
        return null;
    }

    // Zuege aus dem vorigen Stand, die noch nicht am Ziel sind, zuerst (sie sind unterwegs)
    $alte = [];
    foreach (is_array($alt['zuege'] ?? null) ? $alt['zuege'] : [] as $z) {
        if (!is_array($z) || empty($z['zug']) || empty($z['ende']) || !is_array($z['halte'] ?? null)) {
            continue;
        }
        if (strtotime($z['ende']) + KA_NACHLAUF * 60 < $ts) {
            continue;
        }
        $alte[$z['zug']] = $z;
    }
    $grenze = $ts + KA_VORLAUF * 60;
    $queue = [];
    foreach ($alte as $zug => $z) {
        $queue[$zug] = 0;
    }
    uasort($kandidaten, function ($a, $b) {
        return $a['t'] <=> $b['t'];
    });
    foreach ($kandidaten as $zug => $k) {
        if ($k['t'] <= $grenze && !isset($queue[$zug])) {
            $queue[$zug] = 1;
        }
    }
    $zuege = array_slice(array_keys($queue), 0, KA_ZUG_MAX);
    $antworten = $zuege ? abf_fetch_many(array_map('abf_zug_url', $zuege), $fetch) : [];

    $out = [];
    foreach ($zuege as $zug) {
        $detail = ka_parse_zug($antworten[abf_zug_url($zug)] ?? '', $now);
        if ($detail === null && isset($alte[$zug])) {
            // Abfrage gescheitert: der Laufweg aus dem vorigen Stand gilt weiter
            $detail = [
                'zug'     => (string) $zug,
                'linie'   => (string) ($alte[$zug]['linie'] ?? ''),
                'bus'     => false,
                'ursache' => (string) ($alte[$zug]['hinweis'] ?? ''),
                'zuglauf' => '',
                'halte'   => ka_halte_lesen($alte[$zug]['halte']),
            ];
        }
        if ($detail === null || $detail['bus'] || !preg_match('/^RB\d/i', $detail['linie'])) {
            continue;
        }
        if ($detail['zug'] === '') {
            $detail['zug'] = (string) $zug;
        }
        $z = ka_zug_bauen($detail, $now, ['wagen' => $kandidaten[$zug]['wagen'] ?? ($alte[$zug]['wagen'] ?? null)]);
        if ($z !== null) {
            $out[] = $z;
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($a['linie'], $b['linie']) ?: strcmp($a['zug'], $b['zug']);
    });

    return [
        'ok'         => true,
        'fetched_at' => $now->format('c'),
        'stand'      => $stand,
        'quellen'    => $quellen,
        'anzahl'     => count($out),
        'zuege'      => $out,
    ];
}

function ka_leer()
{
    return ['ok' => false, 'fetched_at' => null, 'anzahl' => 0, 'zuege' => []];
}

/** Stand aus dem Cache (KA_TTL Sekunden), sonst frisch; nur ein Prozess fragt den Monitor. */
function ka_cached($fetch = 'abf_fetch', ?DateTime $now = null)
{
    $jetzt = $now ? $now->getTimestamp() : time();
    $cached = nordbahn_read_json(KA_CACHE);
    if (is_array($cached) && !empty($cached['ok']) && isset($cached['fetched_at']) && $jetzt - strtotime($cached['fetched_at']) < KA_TTL) {
        return $cached;
    }
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen(KA_LOCK, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = ka_build($fetch, $now, is_array($cached) ? $cached : null);
        if ($result !== null) {
            nordbahn_write_json(KA_CACHE, $result);
            $cached = $result;
        } elseif (is_array($cached)) {
            $cached['ok'] = false;
        } else {
            $cached = ka_leer();
        }
        flock($lock, LOCK_UN);
    } elseif (!is_array($cached)) {
        $cached = ka_leer();
    }
    if ($lock !== false) {
        fclose($lock);
    }

    return $cached;
}

// Kommandozeile: php api/karte.php <verzeichnis>  mit <ID>.xml je Tafel und zug-<nummer>.xml je Laufweg
if (PHP_SAPI === 'cli') {
    if (!isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
        return; // als Bibliothek eingebunden (Tests)
    }
    $dir = $argv[1] ?? '';
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "Aufruf: php api/karte.php <verzeichnis>\n");
        exit(1);
    }
    $result = ka_build(function ($url) use ($dir) {
        if (preg_match('/zug=(\d+)/', $url, $m)) {
            $f = $dir . '/zug-' . $m[1] . '.xml';
        } elseif (preg_match('/bhf=([A-Z]+)/', $url, $m)) {
            $f = $dir . '/' . $m[1] . '.xml';
        } else {
            return null;
        }

        return is_readable($f) ? file_get_contents($f) : null;
    });
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

// Als Bibliothek eingebunden (karte/index.php setzt NORDBAHN_KARTE_LIB): kein Endpunkt
if (defined('NORDBAHN_KARTE_LIB')) {
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Robots-Tag: noindex');
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$cached = nordbahn_read_json(KA_CACHE);
if (nordbahn_sofort_liefern($cached, KA_TTL, KA_STALE_TTL)) {
    nordbahn_antworten(json_encode($cached, $flags));
    ka_cached();
    exit;
}
echo json_encode(ka_cached(), $flags);
