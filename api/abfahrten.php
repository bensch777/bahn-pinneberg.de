<?php
/**
 * Abfahrten fuer Pendler: vom Heimatbahnhof in die Stadt und aus der Stadt
 * zurueck.
 *
 * URL: /api/abfahrten?bhf=APD
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

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/zuglog.php';

const ABF_API        = 'https://datnet-nbe.etc-consult.de/datnet-nbe/xml_v3';
const ABF_STADT      = ['AH' => 'Hbf', 'AA' => 'Altona']; // Startbahnhoefe fuer den Rueckweg
const ABF_DAMMTOR    = 'ADF';                        // Zwischenhalt der Hbf-Zuege, haengt an deren Zeile
const ABF_AUSFALL_GRACE = 5;                         // Minuten, die ein Ausfall nach seiner Zeit stehen bleibt
const ABF_TEIL_FENSTER  = 40;                        // Minuten, die ein Ausfall vor der Zugauskunft stehen bleibt (Zug startet evtl. erst unterwegs)
const ABF_STADT_IDS  = ['AH', 'AA', 'ADF'];          // Ziele, die "in die Stadt" bedeuten
const ABF_TTL        = 60;                           // Sekunden, die eine Antwort gueltig bleibt
const ABF_STALE_TTL  = 180;                          // Sekunden, die ein alter Stand sofort ausgeliefert und dann im Hintergrund erneuert wird
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

/**
 * Faehrt ein Zug vom Heimatbahnhof aus Richtung Hamburg? Ja bei Hamburger Zielen
 * und bei Zielen, die auf dem Weg nach Hamburg liegen (z. B. "RB71 Pinneberg" ab
 * Prisdorf, wenn die Zuege wegen einer Stoerung in Pinneberg wenden). Auf einem
 * Ast zaehlen nur Ziele desselben Asts oder des Stamms; der Heimatbahnhof selbst
 * als Ziel ist keine Abfahrt.
 */
function abf_richtung_hamburg($zielId, $ziel, $heimat)
{
    if (in_array($zielId, ABF_STADT_IDS, true) || stripos((string) $ziel, 'Hamburg') !== false) {
        return true;
    }
    $bahnhoefe = NORDBAHN_BAHNHOEFE;
    if (!isset($bahnhoefe[$zielId]) || $zielId === $heimat) {
        return false;
    }
    $zielAst = $bahnhoefe[$zielId]['ast'];
    if ($zielAst !== 'stamm' && $zielAst !== $bahnhoefe[$heimat]['ast']) {
        return false;
    }
    $reihe = array_keys($bahnhoefe);

    return array_search($zielId, $reihe, true) < array_search($heimat, $reihe, true);
}

/**
 * Bahnhoefe, an denen Zuege Richtung Norden regulaer beginnen koennen (nachts: Pinneberg,
 * auf den Aesten auch Elmshorn), sofern sie vom Heimatbahnhof aus Richtung Hamburg liegen.
 * Deren Tafeln liefern die Zuege, die auf den Hamburger Tafeln fehlen.
 */
function abf_startbahnhoefe($heimat)
{
    $out = [];
    foreach (['AP' => 'Pinneberg', 'AEL' => 'Elmshorn'] as $id => $name) {
        if (abf_richtung_hamburg($id, $name, $heimat)) {
            $out[$id] = $name;
        }
    }

    return $out;
}

/** curl-Optionen fuer den Stationsmonitor (einzeln und parallel). */
function abf_curl_opts()
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => ABF_TIMEOUT,
        CURLOPT_TIMEOUT        => ABF_TIMEOUT,
        CURLOPT_USERAGENT      => nordbahn_site_host() . ' Monitor',
        CURLOPT_HTTPHEADER     => ['Accept: application/xml, text/xml'],
    ];
}

/** Holt eine URL als Text, oder null bei Fehler. */
function abf_fetch($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, abf_curl_opts());
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $body !== false && $status === 200 ? $body : null;
    }

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => ABF_TIMEOUT, 'user_agent' => nordbahn_site_host() . ' Monitor', 'header' => "Accept: application/xml\r\n"],
    ]));

    return $body === false ? null : $body;
}

/**
 * Holt mehrere URLs gleichzeitig (curl_multi); Ergebnis je URL wie bei abf_fetch,
 * doppelte URLs nur einmal. Mit einem anderen Fetch (Tests, Kommandozeile) laeuft
 * es nacheinander in der uebergebenen Reihenfolge.
 */
function abf_fetch_many(array $urls, $fetch = 'abf_fetch')
{
    $urls = array_values(array_unique($urls));
    $out = [];
    if ($fetch !== 'abf_fetch' || !function_exists('curl_multi_init')) {
        foreach ($urls as $url) {
            $out[$url] = $fetch($url);
        }

        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, abf_curl_opts());
        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);
    foreach ($handles as $url => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $out[$url] = is_string($body) && $body !== '' && $code === 200 ? $body : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
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
    // Hinweis zum Zuglauf, z. B. "Ausfall: hält heute nicht in Pinneberg, Hamburg Dammtor, Hamburg Hbf".
    // Der Text ist verlaesslicher als die ausfall-Flags der Halte: am letzten Halt
    // eines Zuges bleibt das Flag auch dann false, wenn der Halt laut Text entfaellt.
    $zuglauf = '';
    if (isset($doc->zuglaufinfotext)) {
        $zuglauf = trim(preg_replace('/\s+/', ' ', (string) $doc->zuglaufinfotext));
    }
    $entfallen = abf_entfallene_halte($zuglauf);
    foreach ($doc->halt as $halt) {
        if ($text === '') {
            $t = isset($halt->ursacheris) ? trim((string) $halt->ursacheris) : '';
            if ($t !== '') {
                $text = $t;
            }
        }
        $bhf = $halt->bhf ?? null;
        $name = $bhf !== null ? trim((string) $bhf) : '';
        $out['halte'][] = [
            'id'      => $bhf !== null ? trim((string) $bhf['id']) : '',
            'name'    => $name,
            'an'      => abf_text($halt, 'ankunft'),
            'ab'      => abf_text($halt, 'abfahrt'),
            'gleis'   => abf_text($halt, 'gleis'),
            'ausfall' => abf_bool($halt, 'ausfall') || in_array(mb_strtolower($name), $entfallen, true),
            // Kommt der Zug dort noch an? Bei einem Endhalt steht die Ankunft als
            // "pünktlich", nur die Abfahrt als "Halt entfällt!": der Zug wendet dort.
            'an_ok'   => ($p = trim(abf_text($halt, 'anprognose'))) !== '' && stripos($p, 'entf') === false,
        ];
    }
    $out['ursache'] = $text;
    $out['zuglauf'] = $zuglauf;

    return $out;
}

/**
 * Liest aus dem Zuglaufhinweis die Namen der Halte, die heute entfallen
 * ("hält heute nicht in A, B, C"). Kleingeschrieben, fuer den Vergleich.
 */
function abf_entfallene_halte($zuglauf)
{
    if ($zuglauf === '' || !preg_match('/h[aä]lt (?:heute )?nicht in\s+(.+?)(?:\.|$)/iu', $zuglauf, $m)) {
        return [];
    }
    $namen = [];
    foreach (preg_split('/\s*(?:,|\bund\b)\s*/u', $m[1]) as $n) {
        $n = mb_strtolower(trim($n));
        if ($n !== '') {
            $namen[] = $n;
        }
    }

    return $namen;
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
        $letzter = null;   // letzter bedienter Halt hinter dem Heimatbahnhof
        $erster = null;    // erster entfallener Halt hinter dem Heimatbahnhof
        $hamburgFaellt = false;
        for ($i = $hi + 1; $i < $n; $i++) {
            if (!$halte[$i]['ausfall']) {
                $letzter = $i;
                continue;
            }
            if ($erster === null) {
                $erster = $i;
            }
            if (in_array($halte[$i]['id'], ABF_STADT_IDS, true)) {
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
        if ($letzter !== null && in_array($halte[$letzter]['id'], ABF_STADT_IDS, true)) {
            return null; // erreicht Hamburg doch noch, nur ein Zwischenhalt entfaellt
        }
        // Endhalt: der erste entfallene Halt, wenn der Zug dort laut Prognose noch
        // ankommt (Nordbahn meldet "hält nicht in Pinneberg", der Zug wendet aber dort).
        if ($erster !== null && ($letzter === null || $erster > $letzter)
            && $halte[$erster]['an'] !== '' && !empty($halte[$erster]['an_ok'])) {
            if (in_array($halte[$erster]['id'], ABF_STADT_IDS, true)) {
                return null; // wendet erst in Hamburg, kommt also an
            }

            return ['art' => 'endet', 'id' => $halte[$erster]['id'], 'name' => $halte[$erster]['name'], 'zeit' => $halte[$erster]['an']];
        }
        if ($letzter === null) {
            return ['art' => 'endet', 'id' => $heimat, 'name' => $halte[$hi]['name'], 'zeit' => ''];
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

            return ['art' => 'beginnt', 'id' => $halte[$i]['id'], 'name' => $halte[$i]['name'], 'zeit' => $halte[$i]['ab'] ?: $halte[$i]['an'], 'gleis' => (string) ($halte[$i]['gleis'] ?? '')];
        }
    }

    return null;
}

/** Macht aus "Ausfall: hält heute nicht in A, B" den Hinweis "Hält heute nicht in A, B". */
function abf_zuglauf_hinweis($zuglauf)
{
    if (!preg_match('/(h[aä]lt (?:heute )?nicht in\s+.+?)(?:\.|$)/iu', $zuglauf, $m)) {
        return '';
    }

    return mb_strtoupper(mb_substr($m[1], 0, 1)) . mb_substr($m[1], 1);
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
    $changed = false;

    // Reihenfolge der Abfragen: erst die auffaelligen Zuege, dann der Rest
    $queue = [];
    foreach ($lists as $dir => $list) {
        foreach (array_slice($list, 0, ABF_PER_DIR, true) as $i => $row) {
            if ($row['zug'] === '') {
                continue;
            }
            $prio = ($row['ausfall'] || $row['verspaetung'] >= 3 || $row['zuglaufinfo'] || !empty($row['start_pruefen'])) ? 0 : 1;
            $queue[] = [$prio, $dir, $i];
        }
    }
    usort($queue, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    $gueltig = function ($entry) use ($now) {
        return is_array($entry) && isset($entry['halte']) && ($now - (int) ($entry['t'] ?? 0)) <= ABF_ZUG_TTL;
    };

    // Erst alle fehlenden Zugauskuenfte auf einmal holen (in Prioritaetsreihenfolge,
    // hoechstens ABF_ZUG_MAX), dann die Zeilen damit anreichern
    $fehlend = [];
    foreach ($queue as [$prio, $dir, $i]) {
        $zug = $lists[$dir][$i]['zug'];
        if ($gueltig($cache[$zug] ?? null) || in_array($zug, $fehlend, true)) {
            continue;
        }
        if (count($fehlend) >= ABF_ZUG_MAX) {
            break;
        }
        $fehlend[] = $zug;
    }
    if ($fehlend) {
        $antworten = abf_fetch_many(array_map('abf_zug_url', $fehlend), $fetch);
        foreach ($fehlend as $zug) {
            $xml = $antworten[abf_zug_url($zug)] ?? null;
            if ($xml !== null) {
                $cache[$zug] = ['t' => $now] + abf_parse_zug($xml);
                $changed = true;
            }
        }
    }

    foreach ($queue as [$prio, $dir, $i]) {
        $row = &$lists[$dir][$i];
        $entry = $cache[$row['zug']] ?? null;
        if (!$gueltig($entry)) {
            // Ausserhalb des Budgets oder Abfrage fehlgeschlagen: Zeile bleibt, wie sie ist.
            // Ein unbestaetigter Startkandidat faellt weg, sonst stuende ein Durchfahrer
            // faelschlich als "ab Pinneberg" in der Liste.
            if (!empty($row['start_pruefen'])) {
                $row['verwerfen'] = true;
            }
            unset($row);
            continue;
        }
        if (!empty($row['start_pruefen'])) {
            // Beginnt der Zug laut Zuglauf in Hamburg, hat er Hamburg schon verlassen:
            // fuer die Liste "Hamburg → Heimat" vorbei
            $ersterHalt = (string) ($entry['halte'][0]['id'] ?? '');
            if ($ersterHalt === '' || in_array($ersterHalt, ABF_STADT_IDS, true)) {
                $row['verwerfen'] = true;
                unset($row);
                continue;
            }
        }
        $ursache = (string) ($entry['ursache'] ?? '');
        if ($ursache !== '' && stripos($row['hinweis'], $ursache) === false) {
            $row['hinweis'] = trim($ursache . ($row['hinweis'] !== '' ? '. ' . $row['hinweis'] : ''));
        }
        $teil = abf_teilausfall($row, $entry['halte'] ?? [], $heimat, $dir);
        if ($teil !== null && $teil['art'] === 'endet' && $teil['id'] === $heimat) {
            // Faehrt nicht ueber den Heimatbahnhof hinaus: fuer den Pendler ein Ausfall.
            // Als Hinweis der Nordbahn-Text ("hält heute nicht in …"), wenn es ihn gibt.
            $row['ausfall'] = true;
            $row['ist'] = null;
            $grund = abf_zuglauf_hinweis((string) ($entry['zuglauf'] ?? '')) ?: 'Endet heute schon in ' . $teil['name'];
            $row['hinweis'] = trim($grund . ($row['hinweis'] !== '' ? '. ' . $row['hinweis'] : ''));
            $teil = null;
        }
        if ($teil !== null && $teil['art'] === 'beginnt' && $teil['zeit'] !== '' && $row['ausfall']) {
            // Startet erst unterwegs: die Zeile wird zur Abfahrt dort, die Hamburger
            // Planzeit bleibt als Hinweis erhalten
            $start = clone $row['zeit'];
            [$h, $m] = array_map('intval', explode(':', $teil['zeit']));
            $start->setTime($h, $m);
            $row['zeit_plan'] = $row['zeit'];
            $row['zeit'] = $start;
            $row['ist'] = null;
            $row['verspaetung'] = 0;
            $row['ausfall'] = false;
            // Das Gleis gilt fuer den Starthalt, nicht mehr fuer den Hbf
            $row['gleis'] = $teil['gleis'] !== '' ? $teil['gleis'] : null;
            $row['gleis_plan'] = null;
            unset($teil['gleis']);
        }
        $row['teil'] = $teil ?? ($row['teil'] ?? null);
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
    if (!$dt instanceof DateTime) {
        return null;
    }
    // Der Stationsmonitor traegt nach Mitternacht manchmal das falsche Datum (00:08 Uhr
    // mit dem Datum von gestern). Die Tafel zeigt nur die naechsten Stunden, also liegt
    // eine Zeit, die mehr als zwoelf Stunden daneben ist, am anderen Tag.
    $diff = $dt->getTimestamp() - $now->getTimestamp();
    if ($diff < -12 * 3600) {
        $dt->modify('+1 day');
    } elseif ($diff > 12 * 3600) {
        $dt->modify('-1 day');
    }

    return $dt;
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
        $bus = abf_bool($row, 'sev') || abf_bool($row, 'istBus');
        $rest = $ausfall ? '' : trim(preg_replace('/^(pünktlich|punktlich|\+?\s*\d+\s*min\.?)\s*[,;.]?\s*/iu', '', $prognose));
        // Bei Bussen sagt die Prognose nur "Ersatzverkehr ist eingerichtet"; das steht
        // deutlicher als eigenes Merkmal (bus) und Hinweis
        if ($rest !== '' && !preg_match('/^heute\s+gleis/iu', $rest) && !($bus && preg_match('/ersatzverkehr/i', $rest))) {
            $hinweise[] = $rest;
        }
        if ($bus) {
            $hinweise[] = 'Schienenersatzverkehr mit Bus';
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
            'bus'         => $bus,
            'hinweis'     => implode('. ', array_unique(array_map(function ($h) { return rtrim($h, '. '); }, $hinweise))),
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
function abf_build($fetch = 'abf_fetch', $heimat = NORDBAHN_HEIMAT_DEFAULT, ?DateTime $now = null)
{
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $stand = '';
    $quellen = [];

    // Alle vier Tafeln auf einmal holen (Heimat, Hbf, Altona, Dammtor)
    $starts = abf_startbahnhoefe($heimat);
    $tafeln = abf_fetch_many(array_map('abf_url', array_merge([$heimat], array_keys(ABF_STADT), [ABF_DAMMTOR], array_keys($starts))), $fetch);

    $hin = [];
    $xml = $tafeln[abf_url($heimat)] ?? null;
    $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
    $quellen[$heimat] = $rows !== null;
    foreach ($rows ?? [] as $row) {
        if (!abf_richtung_hamburg($row['ziel_id'], $row['ziel'], $heimat)) {
            continue;
        }
        if (!in_array($row['ziel_id'], ABF_STADT_IDS, true) && stripos($row['ziel'], 'Hamburg') === false) {
            // Endet laut Tafel schon unterwegs (z. B. nachts planmaessig in Pinneberg, dann
            // S-Bahn): als "nur bis" zeigen, aber nicht als Stoerung zaehlen ('plan'). Die
            // Zugauskunft ersetzt das, wenn dort Halte entfallen.
            $row['teil'] = ['art' => 'endet', 'id' => $row['ziel_id'], 'name' => $row['ziel'], 'zeit' => '', 'plan' => true];
        }
        $hin[] = $row;
    }

    $rueck = [];
    foreach (ABF_STADT as $id => $label) {
        $xml = $tafeln[abf_url($id)] ?? null;
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
    $xml = $tafeln[abf_url(ABF_DAMMTOR)] ?? null;
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
    // Vor der Zugauskunft bleiben Ausfaelle laenger stehen: ein am Hbf ausgefallener
    // Zug startet vielleicht erst in Pinneberg, dann zaehlt die Abfahrt dort.
    $cutoffTeil = (clone $now)->modify('-' . ABF_TEIL_FENSTER . ' minutes');
    $upcoming = function (array $list) use ($cutoff, $cutoffTeil) {
        return array_values(array_filter($list, function ($row) use ($cutoff, $cutoffTeil) {
            return ($row['ist'] ?: $row['zeit']) >= ($row['ausfall'] ? $cutoffTeil : $cutoff);
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

    // Zuege, die erst unterwegs beginnen (nachts regulaer ab Pinneberg, auf den Aesten
    // auch ab Elmshorn): stehen auf keiner Hamburger Tafel, aber auf der des
    // Startbahnhofs. Kandidat ist, was dort nach Norden faehrt und keine Hamburger
    // Zugnummer traegt; die Zugauskunft bestaetigt den Start (start_pruefen), sonst
    // war es ein Durchfahrer, der Hamburg schon verlassen hat.
    foreach ($dammtor as $d) {
        $gesehen[$d['zug']] = true;
    }
    foreach ($starts as $id => $name) {
        $xml = $tafeln[abf_url($id)] ?? null;
        $rows = $xml !== null ? abf_parse($xml, $now, $stand) : null;
        $quellen[$id] = $rows !== null;
        foreach ($upcoming($rows ?? []) as $row) {
            if ($row['zug'] === '' || isset($gesehen[$row['zug']]) || $row['ausfall']) {
                continue;
            }
            if (abf_richtung_hamburg($row['ziel_id'], $row['ziel'], $heimat) || !abf_erreicht($row['ziel_id'], $heimat)) {
                continue;
            }
            $gesehen[$row['zug']] = true;
            $row['ab'] = $name;
            $row['ab_id'] = $id;
            $row['teil'] = ['art' => 'beginnt', 'id' => $id, 'name' => $name, 'zeit' => $row['zeit']->format('H:i'), 'gleis' => (string) ($row['gleis'] ?? ''), 'plan' => true];
            $row['start_pruefen'] = true;
            $rueck[] = $row;
        }
    }
    usort($rueck, $sort);

    $lists = ['hin' => $upcoming($hin), 'rueck' => $rueck];
    abf_add_zuginfo($lists, $fetch, $heimat);
    $hin = $lists['hin'];
    $rueck = array_values(array_filter($lists['rueck'], function ($row) {
        return empty($row['verwerfen']);
    }));
    usort($hin, $sort);
    usort($rueck, $sort);

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
                'zeit_plan'   => isset($row['zeit_plan']) ? $row['zeit_plan']->format('c') : null,
                'ist'         => $row['ist'] ? $row['ist']->format('c') : null,
                'verspaetung' => $row['verspaetung'],
                'linie'       => $row['linie'],
                'ziel'        => $row['ziel'],
                'gleis'       => $row['gleis'],
                'gleis_plan'  => $row['gleis_plan'],
                'ausfall'     => $row['ausfall'],
                'bus'         => !empty($row['bus']),
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

/**
 * Abfahrten fuer einen Heimatbahnhof aus dem Cache (ABF_TTL Sekunden), sonst
 * frisch vom Stationsmonitor. Nur ein Prozess holt neue Daten, alle anderen
 * liefern derweil den alten Stand. Wird vom Endpunkt und vom Push-Versand genutzt.
 */
function abf_cached($heimat)
{
    $cacheFile = NORDBAHN_CACHE_DIR . '/abfahrten-' . $heimat . '.json';
    $lockFile  = NORDBAHN_CACHE_DIR . '/abfahrten-' . $heimat . '.lock';

    $cached = nordbahn_read_json($cacheFile);
    $fresh = is_array($cached) && isset($cached['fetched_at']) && (time() - strtotime($cached['fetched_at'])) < ABF_TTL;
    if ($fresh) {
        return $cached;
    }
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen($lockFile, 'c');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        $result = abf_build('abf_fetch', $heimat);
        if ($result !== null) {
            nordbahn_write_json($cacheFile, $result);
            zl_log($result, $heimat);
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

    return $cached;
}

/**
 * Darf ein alter Stand sofort ausgeliefert werden? Ja, wenn er brauchbar und
 * zwischen ABF_TTL und ABF_STALE_TTL Sekunden alt ist: der Besucher wartet dann
 * nicht, der Endpunkt erneuert den Stand nach der Antwort im Hintergrund.
 */
function abf_sofort_liefern($cached, $now = null)
{
    return nordbahn_sofort_liefern($cached, ABF_TTL, ABF_STALE_TTL, $now);
}

/** Antwort komplett rausschicken und die Verbindung beenden; danach darf noch gearbeitet werden. */
function abf_antworten($json)
{
    nordbahn_antworten($json);
}

// Auf der Kommandozeile laesst sich die Aufbereitung gegen lokale Dateien pruefen:
// php nordbahn/api/abfahrten.php [--bhf=APD] heimat.xml hbf.xml altona.xml dammtor.xml [zug.xml|zugverzeichnis/]
// Die fuenfte Angabe dient als Antwort fuer alle Zugabfragen (eine Datei) oder liefert je
// Zugnummer <verzeichnis>/<nummer>.xml; der Zug-Cache wird dabei nicht benutzt.
if (PHP_SAPI === 'cli') {
    if (!isset($argv[0]) || realpath($argv[0]) !== __FILE__) {
        return; // als Bibliothek eingebunden (Tests), nicht als Kommando gestartet
    }
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

// Als Bibliothek eingebunden (push.php, notify.php setzen NORDBAHN_ABF_LIB): kein Endpunkt
if (defined('NORDBAHN_ABF_LIB')) {
    return;
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
$cached = nordbahn_read_json(NORDBAHN_CACHE_DIR . '/abfahrten-' . $heimat . '.json');
if (abf_sofort_liefern($cached)) {
    // Alten Stand sofort liefern, dann im Hintergrund erneuern (abf_cached sperrt, damit
    // nur ein Prozess den Stationsmonitor fragt)
    abf_antworten(json_encode(zl_anreichern($cached, $heimat), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    abf_cached($heimat);
    exit;
}
echo json_encode(zl_anreichern(abf_cached($heimat), $heimat), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
