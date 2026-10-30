<?php
/** MCP-Server (api/mcp.php): Protokoll, Bahnhofsnamen und Werkzeuge ohne Netz. */

function t_mcp_quellen(array $data = [], $abf = null)
{
    return [
        'now'  => t_now()->getTimestamp(),
        'data' => function () use ($data) {
            return $data + ['stoerungen' => [], 'stoerungen_verlauf' => [], 'eilmeldungen' => [], 'eilmeldungen_live' => []];
        },
        'abf'  => function ($bhf) use ($abf) {
            return $abf;
        },
        'gf'   => function () {
            return null;
        },
    ];
}

function t_mcp_rpc($method, array $params = [], array $q = [], ?callable $limit = null)
{
    return nb_mcp_verarbeiten(['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params], $q ?: t_mcp_quellen(), $limit);
}

function test_mcp_initialize_und_tools_list()
{
    $r = t_mcp_rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]);
    t_eq(7, $r['id'], 'id kommt zurueck');
    t_eq('2025-06-18', $r['result']['protocolVersion'], 'gewuenschte Version, wenn bekannt');
    t_eq(['listChanged' => false], $r['result']['capabilities']['tools'], 'nur Werkzeuge');
    t_eq(NB_MCP_VERSIONEN[0], t_mcp_rpc('initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion'], 'unbekannte Version: neueste');
    t_eq(null, nb_mcp_verarbeiten(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], t_mcp_quellen()), 'Benachrichtigung ohne Antwort');
    $tools = t_mcp_rpc('tools/list')['result']['tools'];
    t_eq(['lage', 'abfahrten', 'stoerung'], array_column($tools, 'name'), 'drei Werkzeuge');
    foreach ($tools as $t) {
        t_eq(true, $t['annotations']['readOnlyHint'], $t['name'] . ' nur lesend');
    }
    t_eq(-32601, t_mcp_rpc('gibts/nicht')['error']['code'], 'unbekannte Methode');
    t_eq(-32602, t_mcp_rpc('tools/call', ['name' => 'loeschen'])['error']['code'], 'unbekanntes Werkzeug');
    t_eq(-32600, nb_mcp_verarbeiten(['id' => 1, 'method' => 'ping'], t_mcp_quellen())['error']['code'], 'ohne jsonrpc');
}

function test_mcp_bahnhof_aus_name_oder_kuerzel()
{
    t_eq('APD', nb_mcp_bahnhof(''), 'Vorgabe');
    t_eq('AEL', nb_mcp_bahnhof('ael'), 'Kuerzel klein');
    t_eq('AEL', nb_mcp_bahnhof('Elmshorn'), 'Name');
    t_eq('AGST', nb_mcp_bahnhof('Glueckstadt'), 'ue fuer ü');
    t_eq('AGST', nb_mcp_bahnhof('Glückstadt'), 'mit Umlaut');
    t_eq('AHOT', nb_mcp_bahnhof('Horst'), 'Anfang des Namens');
    t_eq(null, nb_mcp_bahnhof('Bremen'), 'unbekannt');
    $r = t_mcp_rpc('tools/call', ['name' => 'lage', 'arguments' => ['bahnhof' => 'Bremen']]);
    t_eq(true, $r['result']['isError'], 'unbekannter Bahnhof ist ein Werkzeugfehler');
    t_contains('Bekannt sind: Pinneberg, Prisdorf', $r['result']['content'][0]['text'], 'nennt die Bahnhoefe');
}

function test_mcp_lage_und_abfahrten()
{
    $abf = ['ok' => true, 'fetched_at' => '2026-10-15T09:59:00+02:00', 'richtungen' => [
        'hin'   => [['zeit' => '2026-10-15T10:13:00+02:00', 'linie' => 'RB71', 'ziel' => 'Hamburg Hbf', 'verspaetung' => 4, 'gleis' => '2']],
        'rueck' => [['zeit' => '2026-10-15T10:20:00+02:00', 'linie' => 'RB61', 'ziel' => 'Itzehoe', 'ab' => 'Hbf', 'ausfall' => true]],
    ]];
    $q = t_mcp_quellen(['received_at' => T_NOW], $abf);
    $lage = t_mcp_rpc('tools/call', ['name' => 'lage', 'arguments' => ['bahnhof' => 'Tornesch']], $q)['result'];
    t_eq(false, isset($lage['isError']), 'kein Fehler');
    t_contains('## Nächste Züge ab Tornesch', $lage['content'][0]['text'], 'lage.md fuer den Bahnhof');
    t_contains('Abgerufen am 15.10.2026 um 10:00 Uhr (Ortszeit Hamburg).', $lage['content'][0]['text'], 'absolute Zeit');

    $text = t_mcp_rpc('tools/call', ['name' => 'abfahrten', 'arguments' => ['richtung' => 'hamburg']], $q)['result']['content'][0]['text'];
    t_contains("## Prisdorf → Hamburg\n\n- 10:13 Uhr (+4 min): RB71 nach Hamburg Hbf. Gleis 2.", $text, 'Hinrichtung');
    t_eq(false, strpos($text, 'Itzehoe') !== false, 'ohne Rueckrichtung');
    $beide = t_mcp_rpc('tools/call', ['name' => 'abfahrten', 'arguments' => []], $q)['result']['content'][0]['text'];
    t_contains('- 10:20 Uhr ab Hbf: RB61 nach Itzehoe fällt aus.', $beide, 'Ausfall in der Rueckrichtung');

    $ohne = t_mcp_rpc('tools/call', ['name' => 'abfahrten', 'arguments' => []], t_mcp_quellen([], ['ok' => false]))['result'];
    t_eq(true, $ohne['isError'], 'ohne Tafel ein Fehler');
    $lageOhne = t_mcp_rpc('tools/call', ['name' => 'lage'], t_mcp_quellen([], ['ok' => false]))['result']['content'][0]['text'];
    t_contains('- Nordbahn ab Prisdorf: keine aktuellen Abfahrtsdaten, Stationsmonitor der Nordbahn gerade nicht erreichbar', $lageOhne, 'kein "Lade Nordbahn" im Text');
    t_eq(false, strpos($lageOhne, 'Lade Nordbahn') !== false, 'Ladehinweis nur auf der Seite');
}

function test_mcp_stoerung_details_und_limit()
{
    @unlink(NB_MCP_ZAEHLER);
    $data = ['stoerungen' => [['key' => 'BZI_1', 'titel' => 'Signalstörung', 'ki_titel' => 'Verspätungen in Elmshorn', 'ki_text' => 'Ein Signal ist kaputt.',
        'text' => 'Störung an einem Signal.', 'wirkungen' => ['Verspätungen'], 'ende' => '2026-10-15T12:00:00+02:00', 'abschnitte' => [['von' => 'Elmshorn', 'bis' => 'Tornesch']]]],
        'stoerungen_verlauf' => [['key' => 'BZI_1', 'titel' => 'Signalstörung', 'first_seen' => '2026-10-15T08:30:00+02:00', 'last_seen' => '2026-10-15T09:58:00+02:00']]];
    $q = t_mcp_quellen($data);
    $text = t_mcp_rpc('tools/call', ['name' => 'stoerung', 'arguments' => ['key' => 'https://bahn-pinneberg.de/stoerung/BZI_1/verspaetungen/']], $q)['result']['content'][0]['text'];
    t_contains('# Verspätungen in Elmshorn', $text, 'Pendler-Titel');
    t_contains('- Status: aktiv seit heute, 08:30 Uhr', $text, 'aktiv');
    t_contains('- Voraussichtlich bis heute, 12:00 Uhr', $text, 'gemeldetes Ende');
    t_contains('- Abschnitt: Elmshorn – Tornesch', $text, 'Abschnitt');
    t_contains("## Originalmeldung (strecken-info.de, DB InfraGO)\n\nSignalstörung\n\nStörung an einem Signal.", $text, 'Original');
    t_eq(false, strpos($text, 'Hinweis ohne') !== false, 'ohne KI-Urteil kein Hinweis');
    t_eq(true, t_mcp_rpc('tools/call', ['name' => 'stoerung', 'arguments' => ['key' => 'NIX']], $q)['result']['isError'], 'unbekannter Key');

    $gesperrt = t_mcp_rpc('tools/call', ['name' => 'lage'], $q, function () {
        return false;
    })['result'];
    t_eq(true, $gesperrt['isError'], 'Limit erreicht');
    t_contains('Zu viele Abfragen', $gesperrt['content'][0]['text'], 'Limit-Text');
    $z = nordbahn_read_json(NB_MCP_ZAEHLER);
    t_eq(['stoerung' => 2], $z['2026-10-15'], 'Zaehler je Tag und Werkzeug, gesperrter Aufruf zaehlt nicht');
}
