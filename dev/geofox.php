<?php
/**
 * Kommandozeile fuer die HVV-Schnittstelle (Geofox GTI), zum Pruefen des Zugangs
 * und der Antwortformate. Nutzt den Zugang aus config.php. Nur per CLI, der
 * Ordner dev/ ist im Web gesperrt.
 *
 *   php dev/geofox.php init                        Verbindung und Protokollversion
 *   php dev/geofox.php checkName "Hamburg Hbf"     Station mit ID
 *   php dev/geofox.php departureList hin|rueck     Rohantwort ab Pinneberg bzw. Hamburg Hbf
 *   php dev/geofox.php getAnnouncements            Rohantwort der HVV-Meldungen
 *   php dev/geofox.php build                       aufbereiteter Stand wie api/s3 (ohne Cache)
 *
 * Die Rohantworten eignen sich als Fixtures fuer tests/nordbahn/GeofoxTest.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/geofox.php';

$zugang = gf_zugang();
if ($zugang === null) {
    fwrite(STDERR, "Kein Zugang: NORDBAHN_GEOFOX_USER und NORDBAHN_GEOFOX_PASSWORD in config.php setzen.\n");
    exit(1);
}
$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';
$now = new DateTime('now', new DateTimeZone('Europe/Berlin'));

/** Rohantwort einer Methode drucken, auch bei returnCode != OK. */
function gf_cli_roh($method, array $body, array $zugang)
{
    $body = ['version' => $zugang['version'], 'language' => 'de'] + $body;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, "POST " . $zugang['url'] . '/' . $method . "\n" . $json . "\n\n");
    $antwort = gf_fetch($method, $json, $zugang);
    if ($antwort === null) {
        fwrite(STDERR, "Keine Antwort (HTTP-Fehler oder Zeitueberschreitung).\n");
        exit(2);
    }
    $daten = json_decode($antwort, true);
    echo is_array($daten) ? json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $antwort, "\n";
}

switch ($cmd) {
    case 'init':
        gf_cli_roh('init', [], $zugang);
        break;
    case 'checkName':
        gf_cli_roh('checkName', ['theName' => ['name' => $arg !== '' ? $arg : 'Pinneberg', 'type' => 'STATION'], 'maxList' => 5], $zugang);
        break;
    case 'departureList':
        $key = $arg === 'rueck' ? 'rueck' : 'hin';
        $stationen = gf_stationen(null, $now->getTimestamp(), $zugang);
        gf_cli_roh('departureList', [
            'station' => $stationen[$key], 'time' => gf_zeit_param($now), 'maxList' => 20, 'maxTimeOffset' => GF_FENSTER_MIN, 'useRealtime' => true,
        ], $zugang);
        break;
    case 'getAnnouncements':
        $ts = $now->getTimestamp();
        gf_cli_roh('getAnnouncements', [
            'names' => GF_LINIEN, 'timeRange' => ['begin' => gmdate('Y-m-d\TH:i:s\Z', $ts - 3600), 'end' => gmdate('Y-m-d\TH:i:s\Z', $ts + 7 * 86400)], 'full' => true, 'filterPlanned' => 'NO_FILTER',
        ], $zugang);
        break;
    case 'build':
        $result = gf_build(null, $now, $zugang);
        echo json_encode($result ?? ['ok' => false, 'hinweis' => 'Keine Abfahrten zu holen, siehe departureList.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        break;
    default:
        fwrite(STDERR, "Aufruf: php dev/geofox.php init | checkName <Name> | departureList hin|rueck | getAnnouncements | build\n");
        exit(1);
}
