<?php
/**
 * Gemeinsame Funktionen fuer den Nordbahn Monitor. Das Wurzelverzeichnis des
 * Repos ist zugleich das Web-Root; config.php und cache/ liegen dort, sind
 * aber per .htaccess von aussen nicht erreichbar.
 *
 * Die Daten kommen nicht von hier, sondern aus n8n: der Workflow
 * "Nordbahn Dashboard Push" sammelt alle 2 Minuten die aktiven Stoerungen
 * (strecken-info.de), die aktuellen Eilmeldungen (nordbahn.de) und die
 * Eilmeldungs-Historie aus seiner Data Table ein und schickt alles per POST
 * an api/push. Der Webserver legt das Paket in cache/ ab
 * und fuehrt nebenbei ein eigenes Logbuch der Stoerungen, weil n8n aufgehobene
 * Stoerungen loescht und es sonst keinen Verlauf gaebe.
 *
 * Diese Datei liefert selbst nichts aus. Wird sie direkt aufgerufen, fuehrt
 * der Server sie aus und antwortet leer.
 */

// Die Tests (tests/run.php) definieren NORDBAHN_CACHE_DIR vorher auf ein
// Wegwerfverzeichnis, damit sie den echten Cache nicht anfassen.
if (!defined('NORDBAHN_CACHE_DIR')) {
    define('NORDBAHN_CACHE_DIR', __DIR__ . '/../cache');
}
define('NORDBAHN_DATA_FILE', NORDBAHN_CACHE_DIR . '/data.json');
define('NORDBAHN_LOG_FILE', NORDBAHN_CACHE_DIR . '/stoerungen-log.json');

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

    $file = __DIR__ . '/../config.php';
    $config = is_readable($file) ? require $file : [];

    return is_array($config) ? $config : [];
}

/**
 * Oeffentliche Adresse der Seite ohne Slash am Ende, z. B. https://bahn-pinneberg.de.
 * Aus NORDBAHN_SITE_URL in config.php, sonst aus dem aufgerufenen Host. Canonical,
 * Open Graph, Sitemap und robots.txt bauen darauf auf; fuer Suchmaschinen sollte
 * der Wert in config.php fest stehen, sobald die Seite unter mehr als einem
 * Hostnamen erreichbar ist.
 */
function nordbahn_site_url()
{
    $config = nordbahn_config();
    $url = isset($config['NORDBAHN_SITE_URL']) ? trim((string) $config['NORDBAHN_SITE_URL']) : '';
    if ($url === '' && !empty($_SERVER['HTTP_HOST']) && preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $url = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    if ($url === '') {
        $url = 'https://bahn-pinneberg.de';
    }

    return rtrim($url, '/');
}

/** Hostname der Seite, z. B. fuer User-Agent-Strings bei Abfragen. */
function nordbahn_site_host()
{
    return (string) (parse_url(nordbahn_site_url(), PHP_URL_HOST) ?: 'bahn-pinneberg.de');
}

/**
 * Script-URL fuer Plausible Analytics (NORDBAHN_PLAUSIBLE_JS in config.php).
 * Leer bedeutet: kein Analytics-Snippet.
 */
function nordbahn_plausible_js()
{
    $config = nordbahn_config();

    return array_key_exists('NORDBAHN_PLAUSIBLE_JS', $config) ? trim((string) $config['NORDBAHN_PLAUSIBLE_JS']) : '';
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
/**
 * Darf ein alter Cache-Stand sofort ausgeliefert werden? Ja, wenn er brauchbar und
 * zwischen $ttl und $staleTtl Sekunden alt ist: der Besucher wartet dann nicht, der
 * Endpunkt erneuert den Stand nach der Antwort im Hintergrund.
 */
function nordbahn_sofort_liefern($cached, $ttl, $staleTtl, $now = null)
{
    if (!is_array($cached) || empty($cached['ok']) || empty($cached['fetched_at'])) {
        return false;
    }
    $alter = ($now ?? time()) - (int) strtotime($cached['fetched_at']);

    return $alter >= $ttl && $alter < $staleTtl;
}

/** Antwort komplett rausschicken und die Verbindung beenden; danach darf noch gearbeitet werden. */
function nordbahn_antworten($json)
{
    ignore_user_abort(true);
    set_time_limit(60);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
}

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
        // strecken-info: "abgelaufen" = gemeldetes Ende vorbei, aber nicht geschlossen
        'abgelaufen'     => !empty($raw['abgelaufen']),
        'betriebsstellen' => nordbahn_text_list($raw['betriebsstellen'] ?? null),
        // Pendler-Text, den der Alert-Workflow per KI formuliert hat (leer, bis er vorliegt)
        'ki_titel'       => nordbahn_text($raw['ki_titel'] ?? '', 120),
        'ki_text'        => nordbahn_text($raw['ki_text'] ?? '', 600),
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
        'id'       => $id,
        'title'    => nordbahn_text($raw['title'] ?? '', 300),
        'seen_at'  => nordbahn_iso($raw['seen_at'] ?? ''),
        // Pendler-Titel und -Text der KI aus dem Eilmeldungs-Workflow, falls vorhanden
        'ki_titel' => nordbahn_text($raw['ki_titel'] ?? '', 160),
        'ki_text'  => nordbahn_text($raw['ki_text'] ?? '', 600),
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
 * Sonderfahrplan der Nordbahn-Seite (Bauarbeiten, Ersatzverkehr) mit Zeitraum. von und bis
 * kommen aus data-day-start und data-day-end der Seite, bis gilt einschliesslich.
 *
 * @return array|null null ohne gueltige ID, Titel oder Zeitraum
 */
function nordbahn_clean_sonderfahrplan($raw)
{
    if (!is_array($raw)) {
        return null;
    }
    $id = nordbahn_text($raw['id'] ?? '', 40);
    $title = nordbahn_text($raw['title'] ?? '', 300);
    $von = nordbahn_text($raw['von'] ?? '', 10);
    $bis = nordbahn_text($raw['bis'] ?? '', 10);
    if (!preg_match('/^sev-\d+$/', $id) || $title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) {
        return null;
    }
    $link = nordbahn_text($raw['link'] ?? '', 500);
    if (!preg_match('#^https://(www\.)?nordbahn\.de/#', $link)) {
        $link = '';
    }

    return ['id' => $id, 'title' => $title, 'von' => $von, 'bis' => $bis, 'link' => $link];
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

    $sonder = [];
    foreach (is_array($raw['sonderfahrplaene'] ?? null) ? $raw['sonderfahrplaene'] : [] as $e) {
        $clean = nordbahn_clean_sonderfahrplan($e);
        if ($clean !== null) {
            $sonder[] = $clean;
        }
        if (count($sonder) >= 50) {
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
        'sonderfahrplaene'  => $sonder,
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
        foreach (['key', 'titel', 'text', 'abschnitte', 'wirkungen', 'einschraenkung', 'beginn', 'ende', 'ki_titel', 'ki_text'] as $field) {
            $entry[$field] = $s[$field];
        }
        // Verlauf fuer die Ereignisseite: jeder Stand von Text, Pendler-Text oder
        // gemeldetem Ende als Schritt, hoechstens 30
        $stand = ['t' => $nowIso, 'titel' => $s['titel'], 'text' => $s['text'], 'ki_titel' => $s['ki_titel'], 'ki_text' => $s['ki_text'], 'ende' => $s['ende']];
        $verlauf = is_array($entry['verlauf'] ?? null) ? $entry['verlauf'] : [];
        $letzter = $verlauf ? end($verlauf) : null;
        $neu = $letzter === null;
        foreach (['text', 'ki_titel', 'ki_text', 'ende'] as $f) {
            if (!$neu && trim((string) ($letzter[$f] ?? '')) !== trim((string) $stand[$f])) {
                $neu = true;
            }
        }
        if ($neu) {
            $stand['t'] = $letzter === null ? $entry['first_seen'] : $nowIso;
            $verlauf[] = $stand;
            if (count($verlauf) > 30) {
                $verlauf = array_merge([$verlauf[0]], array_slice($verlauf, -29));
            }
        }
        $entry['verlauf'] = $verlauf;
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
            'sonderfahrplaene'  => [],
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
