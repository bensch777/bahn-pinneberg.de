<?php
/**
 * Liefert die aktuelle Revision von strecken-info.de als JSON.
 *
 * URL: /api/revision?token=...
 *
 * Hintergrund: Die Stoerungs-API von strecken-info.de (POST /api/stoerungen)
 * verlangt im Body eine Revision und liefert den Datenstand genau dieser
 * Revision. Eine alte Nummer bleibt gueltig, zeigt aber dauerhaft den alten
 * Stand, aufgehobene Stoerungen bleiben darin "aktiv". Die aktuelle Nummer
 * verraet die Seite nur ueber ihren WebSocket (wss://strecken-info.de/api/
 * websocket): nach einem Handshake schickt der Server {"revision": N}.
 *
 * n8n kann keinen WebSocket oeffnen, darum macht dieser Endpunkt den
 * Handshake in reinem PHP (stream_socket_client, Upgrade und Frames von
 * Hand, keine Extension noetig). Der n8n-Workflow "Strecken-Info Stoerungen
 * Alert" ruft ihn vor jedem Abruf auf und setzt die Nummer in den Request.
 *
 * Der Token ist derselbe wie fuer api/push (NORDBAHN_PUSH_TOKEN in
 * config.php), hier als Query-Parameter, weil n8n ihn so am einfachsten
 * mitgibt. Die Antwort wird kurz gecacht, damit nicht jeder Lauf den
 * WebSocket bemueht.
 */

require __DIR__ . '/store.php';

const REV_HOST       = 'strecken-info.de';
const REV_PATH       = '/api/websocket';
const REV_TTL        = 20;   // Sekunden, die eine Revision als frisch gilt
const REV_TIMEOUT    = 10;   // Sekunden fuer Verbindung und Antwort
const REV_CACHE_FILE = NORDBAHN_CACHE_DIR . '/revision.json';

/** Antwortet als JSON und beendet das Skript. */
function rev_respond($status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$config = nordbahn_config();
$expected = (string) ($config['NORDBAHN_PUSH_TOKEN'] ?? '');
if ($expected === '') {
    rev_respond(503, ['ok' => false, 'error' => 'NORDBAHN_PUSH_TOKEN ist in config.php nicht gesetzt.']);
}

$given = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_NORDBAHN_TOKEN'] ?? '');
if ($given === '' || !hash_equals($expected, $given)) {
    rev_respond(403, ['ok' => false, 'error' => 'Token fehlt oder passt nicht.']);
}

$cached = nordbahn_read_json(REV_CACHE_FILE);
if ($cached !== null && isset($cached['revision'], $cached['fetched_at'])
    && time() - strtotime($cached['fetched_at']) < REV_TTL) {
    rev_respond(200, ['ok' => true, 'revision' => (int) $cached['revision'], 'fetched_at' => $cached['fetched_at'], 'cached' => true]);
}

try {
    $revision = rev_fetch();
    $fetchedAt = gmdate('c');
    nordbahn_write_json(REV_CACHE_FILE, ['revision' => $revision, 'fetched_at' => $fetchedAt]);
    rev_respond(200, ['ok' => true, 'revision' => $revision, 'fetched_at' => $fetchedAt, 'cached' => false]);
} catch (Throwable $e) {
    // Letzten bekannten Stand mitgeben, falls es einen gibt; n8n kann dann
    // selbst entscheiden, ob es damit weiterarbeitet.
    rev_respond(502, [
        'ok' => false,
        'error' => $e->getMessage(),
        'revision' => isset($cached['revision']) ? (int) $cached['revision'] : null,
        'fetched_at' => $cached['fetched_at'] ?? null,
    ]);
}

/** Oeffnet den WebSocket, macht den Handshake und gibt die Revision zurueck. */
function rev_fetch()
{
    $ctx = stream_context_create(['ssl' => ['peer_name' => REV_HOST, 'SNI_enabled' => true]]);
    $sock = @stream_socket_client('ssl://' . REV_HOST . ':443', $errno, $errstr, REV_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    if ($sock === false) {
        throw new RuntimeException("Verbindung fehlgeschlagen: {$errstr} ({$errno})");
    }
    stream_set_timeout($sock, REV_TIMEOUT);

    // 1) HTTP-Upgrade auf WebSocket
    $key = base64_encode(random_bytes(16));
    fwrite($sock,
        'GET ' . REV_PATH . " HTTP/1.1\r\n"
        . 'Host: ' . REV_HOST . "\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . 'Origin: https://' . REV_HOST . "\r\n"
        . 'User-Agent: ' . nordbahn_site_host() . "\r\n"
        . "\r\n"
    );

    $headers = '';
    while (($line = fgets($sock)) !== false) {
        $headers .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    if (!preg_match('#^HTTP/1\.1 101#', $headers)) {
        fclose($sock);
        throw new RuntimeException('WebSocket-Upgrade abgelehnt: ' . trim(strtok($headers, "\r\n")));
    }

    // 2) Handshake-Nachricht senden (Client-Frames muessen maskiert sein)
    fwrite($sock, rev_encode_frame(0x1, '{"type":"HANDSHAKE","revision":null}'));

    // 3) Frames lesen, bis eine Revision kommt; PING beantworten, Rest ignorieren
    $deadline = time() + REV_TIMEOUT;
    while (time() < $deadline) {
        $frame = rev_read_frame($sock);
        if ($frame === null) {
            break;
        }
        list($opcode, $data) = $frame;
        if ($opcode === 0x8) {
            break;
        }
        if ($opcode === 0x9) {
            fwrite($sock, rev_encode_frame(0xA, $data));
            continue;
        }
        if ($opcode !== 0x1 || $data === '' || $data[0] !== '{') {
            continue;
        }
        $json = json_decode($data, true);
        $rev = $json['revision'] ?? null;
        if (is_array($rev)) {
            $rev = $rev['nummer'] ?? null;
        }
        if (is_int($rev) && $rev > 0) {
            fwrite($sock, rev_encode_frame(0x8, ''));
            fclose($sock);
            return $rev;
        }
    }
    fclose($sock);
    throw new RuntimeException('Keine Revision vom WebSocket erhalten.');
}

/** Baut einen maskierten WebSocket-Frame (RFC 6455). */
function rev_encode_frame($opcode, $payload)
{
    $len = strlen($payload);
    $frame = chr(0x80 | $opcode);
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $frame .= $mask;
    for ($i = 0; $i < $len; $i++) {
        $frame .= $payload[$i] ^ $mask[$i % 4];
    }

    return $frame;
}

/** Liest einen Frame; gibt [opcode, payload] zurueck oder null bei EOF/Timeout. */
function rev_read_frame($sock)
{
    $head = rev_read_exact($sock, 2);
    if ($head === null) {
        return null;
    }
    $opcode = ord($head[0]) & 0x0F;
    $byte2 = ord($head[1]);
    $masked = ($byte2 & 0x80) !== 0;
    $len = $byte2 & 0x7F;
    if ($len === 126) {
        $len = unpack('n', (string) rev_read_exact($sock, 2))[1];
    } elseif ($len === 127) {
        $len = unpack('J', (string) rev_read_exact($sock, 8))[1];
    }
    $mask = $masked ? rev_read_exact($sock, 4) : null;
    $data = $len > 0 ? rev_read_exact($sock, $len) : '';
    if ($data === null) {
        return null;
    }
    if ($mask !== null) {
        for ($i = 0; $i < $len; $i++) {
            $data[$i] = $data[$i] ^ $mask[$i % 4];
        }
    }

    return [$opcode, $data];
}

/** Liest genau $n Bytes vom Socket, oder null bei EOF/Timeout. */
function rev_read_exact($sock, $n)
{
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($sock);
            if ($meta['timed_out'] || $meta['eof']) {
                return null;
            }
            continue;
        }
        $buf .= $chunk;
    }

    return $buf;
}
