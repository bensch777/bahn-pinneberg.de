<?php
/**
 * MCP-Server (Model Context Protocol) unter /mcp: KI-Assistenten wie Claude oder
 * ChatGPT koennen die Seite als Connector einbinden und dann live fragen, ob es
 * gerade Probleme gibt, statt auf einen veralteten Suchindex angewiesen zu sein.
 *
 * Transport "Streamable HTTP" in der einfachsten Form: jede Anfrage ist ein
 * JSON-RPC-Objekt per POST, die Antwort kommt als JSON. Kein SSE, keine Sitzung,
 * keine Anmeldung, nur lesende Werkzeuge. GET und DELETE antworten mit 405, wie
 * es die Spezifikation fuer Server ohne Stream vorsieht.
 *
 * Werkzeuge:
 *   lage       Lage wie /lage.md fuer einen Heimatbahnhof
 *   abfahrten  naechste Zuege ab dem Heimatbahnhof bzw. aus Hamburg zurueck
 *   stoerung   Details zu einer Infrastrukturstoerung (Key aus lage)
 *
 * Die Werkzeuge lesen dieselben Caches wie die Seite (abf_cached, gf_cached),
 * loesen also keine zusaetzlichen Anfragen bei der Nordbahn aus. Gegen
 * Dauerfeuer gilt ein Limit je Besucher und Stunde (Kennung wie beim Feedback).
 * Aufrufe je Tag und Werkzeug zaehlt cache/mcp-zaehler.json.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/render.php';
if (!defined('NORDBAHN_FEEDBACK_LIB')) {
    define('NORDBAHN_FEEDBACK_LIB', true); // nur Kennung und Limit, nicht der Endpunkt
}
require_once __DIR__ . '/feedback.php';
if (!defined('NORDBAHN_ABF_LIB')) {
    define('NORDBAHN_ABF_LIB', true);
}
require_once __DIR__ . '/abfahrten.php';

const NB_MCP_VERSIONEN = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05']; // neueste zuerst
const NB_MCP_LIMIT     = 120;   // Werkzeugaufrufe je Besucher ...
const NB_MCP_FENSTER   = 3600;  // ... in diesem Zeitraum (Sekunden)
const NB_MCP_ZAEHLER   = NORDBAHN_CACHE_DIR . '/mcp-zaehler.json';
const NB_MCP_ZAEHLER_TAGE = 90;

/** Hinweise fuer das Modell, kommen mit der Antwort auf initialize. */
function nb_mcp_anleitung()
{
    return 'Bahn-Pinneberg.de: inoffizielle Statusseite für Pendler auf den Nordbahn-Linien RB61 (Itzehoe – Hamburg Hbf), '
        . 'RB71 (Wrist – Hamburg Hbf) und RB60 (Itzehoe – Hamburg-Altona). Für Fragen wie „Gibt es gerade Probleme?“ '
        . 'oder „Fährt mein Zug?“ zuerst das Werkzeug lage nutzen, für Abfahrten abfahrten, für Details zu einer '
        . 'Infrastrukturstörung stoerung mit dem Key aus lage. Zeiten sind Ortszeit Europe/Berlin. Verbindliche '
        . 'Auskünfte gibt nur die Nordbahn; nenne bei Antworten die Seite als Quelle.';
}

/** Bahnhofsparameter mit Kuerzeln und Namen fuer die Werkzeugbeschreibung. */
function nb_mcp_bahnhof_schema()
{
    $liste = [];
    foreach (NORDBAHN_BAHNHOEFE as $id => $bhf) {
        $liste[] = $bhf['name'] . ' (' . $id . ')';
    }

    return [
        'type'        => 'string',
        'description' => 'Heimatbahnhof als Name oder Kürzel: ' . implode(', ', $liste) . '. Ohne Angabe ' . NORDBAHN_BAHNHOEFE[NORDBAHN_HEIMAT_DEFAULT]['name'] . '.',
    ];
}

/** Die Werkzeuge fuer tools/list. */
function nb_mcp_werkzeuge()
{
    $nurLesen = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];

    return [
        [
            'name'        => 'lage',
            'title'       => 'Aktuelle Lage',
            'description' => 'Aktuelle Lage auf RB60, RB61 und RB71: Status auf einen Blick, aktive Infrastrukturstörungen (strecken-info.de) mit Pendler-Text, die nächsten Züge ab dem Heimatbahnhof nach Hamburg und zurück mit Ausfällen und Verspätungen, Eilmeldungen der Nordbahn der letzten 24 Stunden, zuletzt aufgehobene Störungen und, falls verfügbar, S3 und HVV-Meldungen. Die erste Wahl für „Gibt es gerade Probleme?“.',
            'inputSchema' => ['type' => 'object', 'properties' => ['bahnhof' => nb_mcp_bahnhof_schema()]],
            'annotations' => $nurLesen,
        ],
        [
            'name'        => 'abfahrten',
            'title'       => 'Nächste Züge',
            'description' => 'Die nächsten Züge der Nordbahn ab dem Heimatbahnhof nach Hamburg (Hbf, Dammtor, Altona) und von Hamburg zurück zum Heimatbahnhof, mit Prognose, Verspätung, Gleis, Ausfall, Teilausfall („nur bis Pinneberg“) und Ersatzabfahrt. Live aus dem Stationsmonitor der Nordbahn, höchstens eine Minute alt.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'bahnhof'  => nb_mcp_bahnhof_schema(),
                'richtung' => ['type' => 'string', 'enum' => ['hamburg', 'zurueck', 'beide'], 'description' => 'hamburg: ab Heimatbahnhof nach Hamburg; zurueck: ab Hamburg zum Heimatbahnhof; beide (Vorgabe).'],
                'anzahl'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => ABF_PER_DIR, 'description' => 'Züge je Richtung, Vorgabe 5.'],
            ]],
            'annotations' => $nurLesen,
        ],
        [
            'name'        => 'stoerung',
            'title'       => 'Störung im Detail',
            'description' => 'Details zu einer Infrastrukturstörung: Pendler-Text, Originalmeldung, betroffene Abschnitte, Auswirkung, Zeitraum, ob sie noch aktiv ist, und die Ereignisseite. Der Key steht in lage (z. B. BZI_33570); auch die Adresse der Ereignisseite wird verstanden.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'key' => ['type' => 'string', 'description' => 'Key der Störung, z. B. BZI_33570, oder die Adresse /stoerung/<key>/.'],
            ], 'required' => ['key']],
            'annotations' => $nurLesen,
        ],
    ];
}

/** Bahnhof aus Name oder Kuerzel ("Elmshorn", "ael", "Glueckstadt"); leer heisst Vorgabe, unbekannt null. */
function nb_mcp_bahnhof($wert)
{
    $wert = trim((string) $wert);
    if ($wert === '') {
        return NORDBAHN_HEIMAT_DEFAULT;
    }
    if (isset(NORDBAHN_BAHNHOEFE[strtoupper($wert)])) {
        return strtoupper($wert);
    }
    $norm = function ($s) {
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($s));

        return preg_replace('/[^a-z]/', '', $s);
    };
    $gesucht = $norm($wert);
    foreach (NORDBAHN_BAHNHOEFE as $id => $bhf) {
        $name = $norm($bhf['name']);
        // "Horst" passt auf "Horst (Holst)"
        if ($gesucht === $name || ($gesucht !== '' && strpos($name, $gesucht) === 0)) {
            return $id;
        }
    }

    return null;
}

/** Werkzeugergebnis als Text; $fehler markiert es als isError fuer das Modell. */
function nb_mcp_text($text, $fehler = false)
{
    $r = ['content' => [['type' => 'text', 'text' => $text]]];
    if ($fehler) {
        $r['isError'] = true;
    }

    return $r;
}

/** "Abgerufen am 30.10.2026 um 08:12 Uhr (Ortszeit Hamburg)." als Kopfzeile, ohne "Heute", das ein Modell spaeter falsch liest. */
function nb_mcp_abgerufen($now)
{
    return 'Abgerufen am ' . (new DateTime('@' . (int) $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y \u\m H:i') . ' Uhr (Ortszeit Hamburg).';
}

/**
 * Fuehrt ein Werkzeug aus. $q liefert die Daten: 'data' (Lagebild), 'abf'
 * (Abfahrten je Bahnhof), 'gf' (S3 oder null), jeweils als Funktion, damit die
 * Tests ohne Netz und Cache auskommen; 'now' ist die Uhrzeit.
 */
function nb_mcp_werkzeug($name, array $args, array $q)
{
    $now = $q['now'] ?? time();
    $unbekannt = function ($wert) {
        return nb_mcp_text('Unbekannter Bahnhof „' . $wert . '“. Bekannt sind: ' . implode(', ', array_column(NORDBAHN_BAHNHOEFE, 'name')) . '.', true);
    };

    if ($name === 'lage') {
        $bhf = nb_mcp_bahnhof($args['bahnhof'] ?? '');
        if ($bhf === null) {
            return $unbekannt($args['bahnhof']);
        }

        return nb_mcp_text(nb_lage_md($q['data'](), $q['abf']($bhf), $bhf, $now, $q['gf']()));
    }

    if ($name === 'abfahrten') {
        $bhf = nb_mcp_bahnhof($args['bahnhof'] ?? '');
        if ($bhf === null) {
            return $unbekannt($args['bahnhof']);
        }
        $richtung = (string) ($args['richtung'] ?? 'beide');
        if (!in_array($richtung, ['hamburg', 'zurueck', 'beide'], true)) {
            $richtung = 'beide';
        }
        $anzahl = max(1, min(ABF_PER_DIR, (int) ($args['anzahl'] ?? 5)));
        $abf = $q['abf']($bhf);
        $bhfName = NORDBAHN_BAHNHOEFE[$bhf]['name'];
        if (!$abf || empty($abf['ok']) || empty($abf['richtungen'])) {
            return nb_mcp_text('Für ' . $bhfName . ' gibt es gerade keine Abfahrtsdaten, der Stationsmonitor der Nordbahn antwortet nicht.', true);
        }
        $text = '# Nächste Züge ab ' . $bhfName . "\n\n" . nb_mcp_abgerufen($now) . ' Stand der Tafel ' . nb_fmt_wann(nb_ts($abf['fetched_at']), $now) . ".\n\n";
        $teile = ['hamburg' => ['hin', $bhfName . ' → Hamburg'], 'zurueck' => ['rueck', 'Hamburg → ' . $bhfName]];
        foreach ($teile as $art => [$feld, $kopf]) {
            if ($richtung !== 'beide' && $richtung !== $art) {
                continue;
            }
            $zuege = array_slice($abf['richtungen'][$feld] ?? [], 0, $anzahl);
            $text .= '## ' . $kopf . "\n\n" . ($zuege ? implode("\n", array_map('nb_md_zug', $zuege)) : '- Keine Abfahrten in der nächsten Zeit.') . "\n\n";
        }

        return nb_mcp_text($text . 'Quelle: Stationsmonitor der Nordbahn über ' . nordbahn_site_url() . "/\n");
    }

    if ($name === 'stoerung') {
        $key = trim((string) ($args['key'] ?? ''));
        if (preg_match('#/stoerung/([A-Za-z0-9_.-]+)#', $key, $m)) {
            $key = $m[1];
        }
        $key = nb_anker($key);
        $st = $key !== '' ? nb_stoerung_finden($q['data'](), $key) : null;
        if ($st === null) {
            return nb_mcp_text('Keine Störung mit dem Key „' . $key . '“ im Logbuch. Die aktiven Störungen mit Key stehen in lage.', true);
        }

        return nb_mcp_text(nb_mcp_stoerung_md($st, $now));
    }

    return null; // unbekanntes Werkzeug, der Aufrufer meldet den Fehler
}

/** Eine Stoerung aus nb_stoerung_finden() als Markdown. */
function nb_mcp_stoerung_md(array $st, $now)
{
    $md = '# ' . $st['titel'] . "\n\n" . nb_mcp_abgerufen($now) . "\n\n";
    $md .= '- Status: ' . ($st['aktiv']
        ? 'aktiv seit ' . nb_fmt_wann($st['start'], $now, true) . ' (' . nb_dauer($now - $st['start']) . ')'
        : 'aufgehoben, zuletzt gemeldet ' . nb_fmt_wann($st['ende'], $now, true) . ', Dauer ' . nb_dauer($st['ende'] - $st['start'])) . "\n";
    $ende = nb_ts($st['ende_gemeldet']);
    if ($st['aktiv'] && $ende !== null) {
        $md .= '- ' . ($st['abgelaufen'] ? 'Gemeldetes Ende ' . nb_fmt_wann($ende, $now, true) . ' überschritten, dauert an' : 'Voraussichtlich bis ' . nb_fmt_wann($ende, $now, true)) . "\n";
    }
    $abschnitte = [];
    foreach ($st['abschnitte'] as $a) {
        $teil = implode(' – ', array_filter([$a['von'] ?? '', $a['bis'] ?? '']));
        if ($teil !== '') {
            $abschnitte[] = $teil;
        }
    }
    if ($abschnitte) {
        $md .= '- Abschnitt: ' . implode(', ', $abschnitte) . "\n";
    }
    if ($st['wirkungen']) {
        $md .= '- Auswirkung: ' . implode(', ', $st['wirkungen']) . "\n";
    }
    if ($st['einschraenkung'] !== '') {
        $md .= '- Einschränkung laut strecken-info.de: ' . $st['einschraenkung'] . "\n";
    }
    // Nur das KI-Urteil: nb_stoerung_finden() setzt fehlende Wirkungen auf [], das waere sonst immer ein Hinweis
    if ($st['ki_folgen'] === 'keine') {
        $md .= "- Einordnung: Hinweis ohne spürbare Folgen für Pendler, zählt nicht als Störung\n";
    }
    $md .= '- Seite: ' . nb_ereignis_url('stoerung', $st['key'], $st['titel']) . "\n\n";
    if ($st['ki_text'] !== '') {
        $md .= "## Für Pendler\n\n" . $st['ki_text'] . "\n\n";
    }
    if ($st['text'] !== '') {
        $md .= "## Originalmeldung (strecken-info.de, DB InfraGO)\n\n" . ($st['titel_technisch'] !== '' ? $st['titel_technisch'] . "\n\n" : '') . $st['text'] . "\n";
    }

    return $md;
}

/** Fehlerobjekt nach JSON-RPC. */
function nb_mcp_fehler($id, $code, $text)
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $text]];
}

/**
 * Verarbeitet eine JSON-RPC-Nachricht. Ergebnis: Antwort als Array, oder null
 * fuer Benachrichtigungen (ohne id), auf die keine Antwort kommt. $limit wird
 * nur vor Werkzeugaufrufen gefragt und liefert false, wenn das Limit erreicht ist.
 */
function nb_mcp_verarbeiten($msg, array $q, ?callable $limit = null)
{
    if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method']) || !is_string($msg['method'])) {
        return nb_mcp_fehler(is_array($msg) ? ($msg['id'] ?? null) : null, -32600, 'Invalid Request');
    }
    if (!array_key_exists('id', $msg)) {
        return null; // notifications/initialized, notifications/cancelled usw.
    }
    $id = $msg['id'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $ok = function ($result) use ($id) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    };

    switch ($msg['method']) {
        case 'initialize':
            $gewuenscht = (string) ($params['protocolVersion'] ?? '');

            return $ok([
                'protocolVersion' => in_array($gewuenscht, NB_MCP_VERSIONEN, true) ? $gewuenscht : NB_MCP_VERSIONEN[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'bahn-pinneberg', 'title' => 'Bahn-Pinneberg.de', 'version' => '1.0.0', 'websiteUrl' => nordbahn_site_url() . '/'],
                'instructions'    => nb_mcp_anleitung(),
            ]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            return $ok(['tools' => nb_mcp_werkzeuge()]);
        case 'tools/call':
            $name = (string) ($params['name'] ?? '');
            if (!in_array($name, array_column(nb_mcp_werkzeuge(), 'name'), true)) {
                return nb_mcp_fehler($id, -32602, 'Unbekanntes Werkzeug: ' . $name);
            }
            if ($limit !== null && !$limit()) {
                return $ok(nb_mcp_text('Zu viele Abfragen in der letzten Stunde, bitte später noch einmal.', true));
            }
            nb_mcp_zaehlen($name, $q['now'] ?? time());

            return $ok(nb_mcp_werkzeug($name, is_array($params['arguments'] ?? null) ? $params['arguments'] : [], $q));
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
    }

    return nb_mcp_fehler($id, -32601, 'Method not found: ' . $msg['method']);
}

/** Zaehlt einen Werkzeugaufruf je Tag; haelt NB_MCP_ZAEHLER_TAGE Tage vor. */
function nb_mcp_zaehlen($werkzeug, $now)
{
    $tag = (new DateTime('@' . (int) $now))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    $z = nordbahn_read_json(NB_MCP_ZAEHLER);
    $z = is_array($z) ? $z : [];
    $z[$tag][$werkzeug] = ($z[$tag][$werkzeug] ?? 0) + 1;
    ksort($z);
    $z = array_slice($z, -NB_MCP_ZAEHLER_TAGE, null, true);
    nordbahn_write_json(NB_MCP_ZAEHLER, $z);
}

if (PHP_SAPI === 'cli') {
    return; // als Bibliothek eingebunden (Tests)
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Protocol-Version, Mcp-Session-Id, Last-Event-ID');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($methode !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    header('Content-Type: text/plain; charset=utf-8');
    echo "MCP-Server von Bahn-Pinneberg.de (Streamable HTTP, nur POST). Als Connector in Claude, ChatGPT oder einem anderen MCP-Client diese Adresse eintragen: " . nordbahn_site_url() . "/mcp\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$msg = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($msg)) {
    echo json_encode(nb_mcp_fehler(null, -32700, 'Parse error'));
    exit;
}
$q = [
    'now'  => time(),
    'data' => 'nordbahn_dataset',
    'abf'  => 'abf_cached',
    'gf'   => function () {
        return gf_aktiv() ? gf_cached() : null;
    },
];
$limit = function () {
    return nb_feedback_limit(nb_feedback_kennung($_SERVER), NORDBAHN_CACHE_DIR . '/mcp-limit', null, NB_MCP_LIMIT, NB_MCP_FENSTER);
};
$antwort = nb_mcp_verarbeiten($msg, $q, $limit);
if ($antwort === null) {
    http_response_code(202);
    exit;
}
echo json_encode($antwort, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
