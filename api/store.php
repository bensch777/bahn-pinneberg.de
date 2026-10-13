<?php
/**
 * Gemeinsame Funktionen fuer den Nordbahn Monitor (nordbahn.bensch777.de,
 * zugleich /nordbahn/ auf bensch777.de). Alles, was der Monitor braucht, liegt
 * in diesem Ordner, weil er das Wurzelverzeichnis der Subdomain ist; config.php
 * und cache/ der Hauptseite sind ueber das Dateisystem trotzdem erreichbar.
 *
 * Die Daten kommen nicht von hier, sondern aus n8n: der Workflow
 * "Nordbahn Dashboard Push" sammelt alle 2 Minuten die aktiven Stoerungen
 * (strecken-info.de), die aktuellen Eilmeldungen (nordbahn.de) und die
 * Eilmeldungs-Historie aus seiner Data Table ein und schickt alles per POST
 * an api/push. Der Webserver legt das Paket in cache/nordbahn/ ab
 * und fuehrt nebenbei ein eigenes Logbuch der Stoerungen, weil n8n aufgehobene
 * Stoerungen loescht und es sonst keinen Verlauf gaebe.
 *
 * Diese Datei liefert selbst nichts aus. Wird sie direkt aufgerufen, fuehrt
 * der Server sie aus und antwortet leer.
 */

const NORDBAHN_CACHE_DIR = __DIR__ . '/../../cache/nordbahn';
const NORDBAHN_DATA_FILE = NORDBAHN_CACHE_DIR . '/data.json';
const NORDBAHN_LOG_FILE  = NORDBAHN_CACHE_DIR . '/stoerungen-log.json';

// So lange bleibt eine aufgehobene Stoerung im Logbuch, gerechnet ab dem
// letzten Zeitpunkt, zu dem n8n sie noch als aktiv gemeldet hat.
const NORDBAHN_LOG_KEEP_DAYS = 400;

// Bahnhoefe, die sich als Heimat waehlen lassen, in Streckenreihenfolge ab
// Hamburg. Die IDs sind die des Nordbahn-Stationsmonitors. 'ast' sagt, welche
// Zuege dort halten: auf dem Stamm bis Elmshorn alle, dahinter nur die nach
// Itzehoe (RB61) oder nur die nach Wrist (RB71).
const NORDBAHN_BAHNHOEFE = [
    'AP'   => ['name' => 'Pinneberg',     'ast' => 'stamm'],
    'APD'  => ['name' => 'Prisdorf',      'ast' => 'stamm'],
    'ATM'  => ['name' => 'Tornesch',      'ast' => 'stamm'],
    'AEL'  => ['name' => 'Elmshorn',      'ast' => 'stamm'],
    'AHZH' => ['name' => 'Herzhorn',      'ast' => 'itzehoe'],
    'AGST' => ['name' => 'Glückstadt',    'ast' => 'itzehoe'],
    'AKM'  => ['name' => 'Krempe',        'ast' => 'itzehoe'],
    'AKHD' => ['name' => 'Kremperheide',  'ast' => 'itzehoe'],
    'AIZ'  => ['name' => 'Itzehoe',       'ast' => 'itzehoe'],
    'AHOT' => ['name' => 'Horst (Holst)', 'ast' => 'wrist'],
    'ADH'  => ['name' => 'Dauenhof',      'ast' => 'wrist'],
    'AWST' => ['name' => 'Wrist',         'ast' => 'wrist'],
];
const NORDBAHN_HEIMAT_DEFAULT = 'APD';

/** Liest config.php aus dem Web-Root. Fehlt sie, bleibt die Liste leer. */
function nordbahn_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = __DIR__ . '/../../config.php';
    $config = is_readable($file) ? require $file : [];

    return is_array($config) ? $config : [];
}

/** Liest eine JSON-Datei als Array, oder null wenn sie fehlt oder kaputt ist. */
function nordbahn_read_json($file)
{
    if (!is_readable($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/**
 * Schreibt ein Array als JSON. Erst in eine Nachbardatei, dann per rename,
 * damit ein gleichzeitiger Leser nie eine halb geschriebene Datei erwischt.
 */
function nordbahn_write_json($file, $data)
{
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    if (!is_dir(NORDBAHN_CACHE_DIR) || !is_writable(NORDBAHN_CACHE_DIR)) {
        return false;
    }

    $tmp = $file . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Normalisiert einen Zeitstempel auf ISO 8601 in UTC. strecken-info.de
 * liefert lokale Zeiten ohne Zone ("2026-10-13T08:11:54"), die als
 * Europe/Berlin gelesen werden; Angaben mit Zone behalten ihre Zone.
 *
 * @return string leer, wenn der Wert nicht lesbar ist
 */
function nordbahn_iso($value)
{
    if (!is_scalar($value)) {
        return '';
    }
    $raw = trim((string) $value);
    if ($raw === '' || strlen($raw) > 40) {
        return '';
    }
    try {
        $date = new DateTime($raw, new DateTimeZone('Europe/Berlin'));
    } catch (Exception $e) {
        return '';
    }
    $date->setTimezone(new DateTimeZone('UTC'));

    return $date->format('Y-m-d\TH:i:s\Z');
}

/** Kuerzt einen Wert auf einen Text begrenzter Laenge; alles andere wird leer. */
function nordbahn_text($value, $max = 2000)
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }

    return substr($text, 0, $max);
}

/** Liste von Texten, leere Eintraege raus, Anzahl und Laenge begrenzt. */
function nordbahn_text_list($value, $maxItems = 20, $maxLen = 200)
{
    $out = [];
    foreach (is_array($value) ? $value : [] as $item) {
        $text = nordbahn_text($item, $maxLen);
        if ($text !== '') {
            $out[] = $text;
        }
        if (count($out) >= $maxItems) {
            break;
        }
    }

    return $out;
}

/**
 * Bringt eine Stoerung aus dem Push auf die Felder, die das Dashboard kennt.
 *
 * @return array|null null ohne Schluessel
 */
function nordbahn_clean_stoerung($raw)
{
    if (!is_array($raw)) {
        return null;
    }
    $key = nordbahn_text($raw['key'] ?? '', 80);
    if ($key === '') {
        return null;
    }

    $abschnitte = [];
    foreach (is_array($raw['abschnitte'] ?? null) ? $raw['abschnitte'] : [] as $a) {
        if (!is_array($a)) {
            continue;
        }
        $abschnitte[] = [
            'von'     => nordbahn_text($a['von'] ?? '', 120),
            'bis'     => nordbahn_text($a['bis'] ?? '', 120),
            'strecke' => is_numeric($a['strecke'] ?? null) ? (int) $a['strecke'] : null,
        ];
        if (count($abschnitte) >= 20) {
            break;
        }
    }

    return [
        'key'            => $key,
        'titel'          => nordbahn_text($raw['titel'] ?? '', 200),
        'text'           => nordbahn_text($raw['text'] ?? ''),
        'abschnitte'     => $abschnitte,
        'wirkungen'      => nordbahn_text_list($raw['wirkungen'] ?? null),
        'einschraenkung' => nordbahn_text($raw['einschraenkung'] ?? '', 40),
        'beginn'         => nordbahn_iso($raw['beginn'] ?? ''),
        'ende'           => nordbahn_iso($raw['ende'] ?? ''),
        'betriebsstellen' => nordbahn_text_list($raw['betriebsstellen'] ?? null),
    ];
}

/** Eine Eilmeldung aus der Historie (Data Table): ID, Titel, Erfassungszeit. */
function nordbahn_clean_eilmeldung($raw)
{
    if (!is_array($raw)) {
        return null;
    }
    $id = nordbahn_text($raw['id'] ?? '', 40);
    if ($id === '') {
        return null;
    }

    return [
        'id'      => $id,
        'title'   => nordbahn_text($raw['title'] ?? '', 300),
        'seen_at' => nordbahn_iso($raw['seen_at'] ?? ''),
    ];
}

/** Eine Eilmeldung von der Nordbahn-Seite: zusaetzlich Stand und Link. */
function nordbahn_clean_live($raw)
{
    $item = nordbahn_clean_eilmeldung($raw);
    if ($item === null) {
        return null;
    }
    unset($item['seen_at']);

    $link = nordbahn_text($raw['link'] ?? '', 500);
    // Nur Links auf nordbahn.de durchlassen, alles andere wird nicht verlinkt.
    if (!preg_match('#^https://(www\.)?nordbahn\.de/#', $link)) {
        $link = '';
    }

    $item['stand'] = nordbahn_iso($raw['stand'] ?? '');
    $item['link'] = $link;

    return $item;
}

/**
 * Bereinigt ein komplettes Push-Paket.
 *
 * @return array|null null, wenn die Grundstruktur nicht passt
 */
function nordbahn_clean_payload($raw)
{
    if (!is_array($raw) || !isset($raw['eilmeldungen']) || !is_array($raw['eilmeldungen'])) {
        return null;
    }

    $stoerungen = [];
    foreach (is_array($raw['stoerungen'] ?? null) ? $raw['stoerungen'] : [] as $s) {
        $clean = nordbahn_clean_stoerung($s);
        if ($clean !== null) {
            $stoerungen[] = $clean;
        }
        if (count($stoerungen) >= 50) {
            break;
        }
    }

    $live = [];
    foreach (is_array($raw['eilmeldungen_live'] ?? null) ? $raw['eilmeldungen_live'] : [] as $e) {
        $clean = nordbahn_clean_live($e);
        if ($clean !== null) {
            $live[] = $clean;
        }
        if (count($live) >= 100) {
            break;
        }
    }

    $historie = [];
    foreach ($raw['eilmeldungen'] as $e) {
        $clean = nordbahn_clean_eilmeldung($e);
        if ($clean !== null) {
            $historie[] = $clean;
        }
        if (count($historie) >= 10000) {
            break;
        }
    }

    $quellen = is_array($raw['quellen'] ?? null) ? $raw['quellen'] : [];

    return [
        'generated_at'      => nordbahn_iso($raw['generated_at'] ?? ''),
        'quellen'           => [
            'strecken_info_ok' => !empty($quellen['strecken_info_ok']),
            'nordbahn_ok'      => !empty($quellen['nordbahn_ok']),
        ],
        'stoerungen'        => $stoerungen,
        'eilmeldungen_live' => $live,
        'eilmeldungen'      => $historie,
    ];
}

/**
 * Traegt die aktiven Stoerungen ins Logbuch ein: neue bekommen first_seen,
 * bekannte nur ein frisches last_seen und die aktuellen Felder. Eintraege,
 * die lange nicht mehr gemeldet wurden, fliegen raus.
 */
function nordbahn_update_log(array $stoerungen, $nowIso)
{
    $log = nordbahn_read_json(NORDBAHN_LOG_FILE);
    if (!is_array($log)) {
        $log = [];
    }

    foreach ($stoerungen as $s) {
        $key = $s['key'];
        $entry = isset($log[$key]) && is_array($log[$key]) ? $log[$key] : [];
        if (empty($entry['first_seen'])) {
            $entry['first_seen'] = $s['beginn'] !== '' ? $s['beginn'] : $nowIso;
        }
        $entry['last_seen'] = $nowIso;
        foreach (['key', 'titel', 'text', 'abschnitte', 'wirkungen', 'einschraenkung', 'beginn', 'ende'] as $field) {
            $entry[$field] = $s[$field];
        }
        $log[$key] = $entry;
    }

    $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - NORDBAHN_LOG_KEEP_DAYS * 86400);
    foreach ($log as $key => $entry) {
        if (!is_array($entry) || empty($entry['last_seen']) || $entry['last_seen'] < $cutoff) {
            unset($log[$key]);
        }
    }

    nordbahn_write_json(NORDBAHN_LOG_FILE, $log);

    return count($log);
}

/**
 * Alles, was die Seite und api/data brauchen: das letzte Paket plus das
 * Stoerungs-Logbuch, neueste zuerst. Ohne Push bleibt received_at null.
 */
function nordbahn_dataset()
{
    $data = nordbahn_read_json(NORDBAHN_DATA_FILE);
    if (!is_array($data)) {
        $data = [
            'received_at'       => null,
            'generated_at'      => '',
            'quellen'           => ['strecken_info_ok' => false, 'nordbahn_ok' => false],
            'stoerungen'        => [],
            'eilmeldungen_live' => [],
            'eilmeldungen'      => [],
        ];
    }

    $log = nordbahn_read_json(NORDBAHN_LOG_FILE);
    $verlauf = [];
    foreach (is_array($log) ? $log : [] as $entry) {
        if (is_array($entry) && !empty($entry['first_seen'])) {
            $verlauf[] = $entry;
        }
    }
    usort($verlauf, function ($a, $b) {
        return strcmp($b['first_seen'], $a['first_seen']);
    });
    $data['stoerungen_verlauf'] = array_slice($verlauf, 0, 1000);

    return $data;
}
