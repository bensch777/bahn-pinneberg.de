<?php
/**
 * Demo-Daten fuer die lokale Entwicklung: fuellt den Cache mit einem erfundenen,
 * aber realistischen Stand, damit Startseite, Meldungen, Live-Karte und Statistik
 * ohne n8n, ohne Stationsmonitor und ohne HVV-Zugang etwas zeigen. Vor allem laesst
 * sich so eine Stoerungslage ansehen, ohne auf die naechste echte zu warten.
 *
 *   NORDBAHN_DEMO=stoerung php -S 127.0.0.1:8080 dev/router.php
 *
 * Der Router ruft diese Datei vor jeder Seite und jedem API-Aufruf auf (hoechstens
 * alle DEMO_TTL Sekunden), alle Zeiten sind relativ zu jetzt. Das Szenario laesst
 * sich im Browser wechseln: /?demo=ruhig, /?demo=leicht, /?demo=stoerung, /?demo=bau.
 *
 *   ruhig     alles planmaessig, letzte Eilmeldung vor sechs Stunden
 *   leicht    einzelne Verspaetungen, eine gerade aufgehobene Stoerung
 *   stoerung  Signalstoerung bei Tornesch: Ausfall, Verspaetungen, Gleiswechsel, Zug nur
 *             bis Pinneberg, Ersatz ab Altona, Eilmeldung, HVV-Meldung zur S3
 *   bau       Baufahrplan: RB61 und RB71 fahren ab/bis Altona
 *
 * Der Demo-Cache liegt im Temp-Verzeichnis (demo_cache_dir), der echte cache/ und
 * config.php bleiben unberuehrt. Die Tafeln und Zugauskuenfte entstehen mit den
 * Bausteinen der Tests (t_tafel, t_zug) und laufen durch dieselbe Aufbereitung wie
 * die echten Antworten (abf_build, ka_build); S3 und DB Regio stehen direkt im
 * Format von api/s3 und api/regio.
 *
 * Auf der Kommandozeile: php dev/demo.php [szenario]
 */

/** Verzeichnis des Demo-Caches; der Router setzt NORDBAHN_CACHE_DIR darauf. NORDBAHN_DEMO_DIR legt es um (Tests). */
function demo_cache_dir()
{
    $dir = getenv('NORDBAHN_DEMO_DIR');

    return is_string($dir) && $dir !== '' ? rtrim($dir, '/\\') : rtrim(sys_get_temp_dir(), '/\\') . '/nordbahn-demo';
}

const DEMO_SZENARIEN = ['ruhig', 'leicht', 'stoerung', 'bau'];
const DEMO_TTL = 20; // Sekunden, die ein Demo-Stand gilt, bevor der Router neu fuellt

/** Gewaehltes Szenario: aus der Datei im Demo-Cache (per ?demo= gesetzt), sonst $vorgabe. */
function demo_szenario($vorgabe = 'ruhig')
{
    $datei = demo_cache_dir() . '/demo-szenario';
    $wahl = is_readable($datei) ? trim((string) file_get_contents($datei)) : '';
    if (in_array($wahl, DEMO_SZENARIEN, true)) {
        return $wahl;
    }

    return in_array($vorgabe, DEMO_SZENARIEN, true) ? $vorgabe : 'ruhig';
}

function demo_szenario_setzen($szenario)
{
    if (!in_array($szenario, DEMO_SZENARIEN, true)) {
        return;
    }
    if (!is_dir(demo_cache_dir())) {
        @mkdir(demo_cache_dir(), 0775, true);
    }
    file_put_contents(demo_cache_dir() . '/demo-szenario', $szenario);
    @unlink(demo_cache_dir() . '/demo-stand');
}

/** Ist der Demo-Stand fuer dieses Szenario noch frisch? */
function demo_frisch($szenario)
{
    $datei = demo_cache_dir() . '/demo-stand';

    return is_readable($datei) && trim((string) file_get_contents($datei)) === $szenario && time() - (int) filemtime($datei) < DEMO_TTL;
}

// Vom Router nur fuer die Helfer oben eingebunden: der Rest laedt die API-Dateien,
// die der Router danach selbst als Endpunkt einbindet
if (defined('NORDBAHN_DEMO_NUR_HELFER')) {
    return;
}

if (!defined('NORDBAHN_CACHE_DIR')) {
    define('NORDBAHN_CACHE_DIR', demo_cache_dir());
}
if (!defined('NORDBAHN_CONFIG_FILE')) {
    define('NORDBAHN_CONFIG_FILE', __DIR__ . '/demo-config.php');
}
if (!defined('NORDBAHN_ABF_LIB')) {
    define('NORDBAHN_ABF_LIB', true);
}
if (!defined('NORDBAHN_KARTE_LIB')) {
    define('NORDBAHN_KARTE_LIB', true);
}
require_once __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require_once __DIR__ . '/../api/abfahrten.php';
require_once __DIR__ . '/../api/geofox.php';
require_once __DIR__ . '/../api/karte.php';
require_once __DIR__ . '/../tests/support.php';

const DEMO_NAMEN = [
    'AH' => 'Hamburg Hbf', 'ADF' => 'Hamburg Dammtor', 'AA' => 'Hamburg-Altona', 'AP' => 'Pinneberg', 'APD' => 'Prisdorf',
    'ATM' => 'Tornesch', 'AEL' => 'Elmshorn', 'AHZH' => 'Herzhorn', 'AGST' => 'Glückstadt', 'AKM' => 'Krempe',
    'AKHD' => 'Kremperheide', 'AIZ' => 'Itzehoe', 'AHOT' => 'Horst (Holst)', 'ADH' => 'Dauenhof', 'AWST' => 'Wrist',
];

/** Laufwege je Linie und Richtung (s nach Hamburg, n nach Norden): [id, Ankunft in Minuten ab Start, Abfahrt, Gleis]. */
const DEMO_LAEUFE = [
    'RB71-s' => ['linie' => 'RB 71', 'wagen' => 1, 'halte' => [
        ['AWST', null, 0, '1'], ['ADH', 5, 5, '1'], ['AHOT', 9, 9, '1'], ['AEL', 14, 15, '2'], ['ATM', 20, 21, '2'],
        ['APD', 24, 25, '2'], ['AP', 28, 29, '3'], ['ADF', 44, 45, '4'], ['AH', 48, null, '13'],
    ]],
    'RB61-s' => ['linie' => 'RB 61', 'wagen' => 2, 'halte' => [
        ['AIZ', null, 0, '2'], ['AKHD', 5, 5, '1'], ['AKM', 9, 9, '1'], ['AGST', 15, 15, '1'], ['AHZH', 20, 20, '1'],
        ['AEL', 26, 27, '2'], ['ATM', 32, 33, '2'], ['APD', 36, 37, '2'], ['AP', 40, 41, '3'], ['ADF', 56, 57, '4'], ['AH', 60, null, '13'],
    ]],
    'RB60-s' => ['linie' => 'RB 60', 'wagen' => 1, 'halte' => [
        ['AEL', null, 0, '3'], ['ATM', 5, 6, '2'], ['APD', 9, 10, '2'], ['AP', 13, 14, '3'], ['AA', 28, null, '9'],
    ]],
    'RB71-n' => ['linie' => 'RB 71', 'wagen' => 1, 'halte' => [
        ['AH', null, 0, '13'], ['ADF', 3, 4, '3'], ['AP', 19, 20, '2'], ['APD', 23, 23, '1'], ['ATM', 27, 28, '1'],
        ['AEL', 32, 33, '1'], ['AHOT', 39, 39, '2'], ['ADH', 43, 43, '2'], ['AWST', 48, null, '2'],
    ]],
    'RB61-n' => ['linie' => 'RB 61', 'wagen' => 2, 'halte' => [
        ['AH', null, 0, '13'], ['ADF', 3, 4, '3'], ['AP', 19, 20, '2'], ['APD', 23, 23, '1'], ['ATM', 27, 28, '1'],
        ['AEL', 32, 33, '1'], ['AHZH', 39, 39, '2'], ['AGST', 44, 44, '2'], ['AKM', 50, 50, '2'], ['AKHD', 54, 54, '2'], ['AIZ', 59, null, '1'],
    ]],
    'RB60-n' => ['linie' => 'RB 60', 'wagen' => 1, 'halte' => [
        ['AA', null, 0, '9'], ['AP', 14, 15, '2'], ['APD', 18, 18, '1'], ['ATM', 22, 23, '1'], ['AEL', 27, null, '3'],
    ]],
];

/** Startminute je Stunde und Zugnummernkreis je Laufweg. */
const DEMO_TAKT = [
    'RB71-s' => [53, 81801], 'RB61-s' => [3, 75501], 'RB60-s' => [44, 28501],
    'RB71-n' => [17, 81800], 'RB61-n' => [56, 75500], 'RB60-n' => [22, 28500],
];

/** Alle Fahrten von vier Stunden vor bis vier Stunden nach $now: nr => [nr, key, linie, wagen, halte] mit DateTime je Halt. */
function demo_fahrten(DateTime $now)
{
    $out = [];
    $basis = (clone $now)->setTime((int) $now->format('H'), 0, 0);
    for ($dh = -4; $dh <= 4; $dh++) {
        $stunde = (clone $basis)->modify(($dh >= 0 ? '+' : '') . $dh . ' hours');
        foreach (DEMO_TAKT as $key => [$minute, $kreis]) {
            $start = (clone $stunde)->modify('+' . $minute . ' minutes');
            $nr = (string) ($kreis + 2 * (int) $stunde->format('H'));
            $halte = [];
            foreach (DEMO_LAEUFE[$key]['halte'] as [$id, $an, $ab, $gleis]) {
                $halte[] = [
                    'id' => $id, 'name' => DEMO_NAMEN[$id], 'gleis' => $gleis,
                    'an' => $an === null ? null : (clone $start)->modify('+' . $an . ' minutes'),
                    'ab' => $ab === null ? null : (clone $start)->modify('+' . $ab . ' minutes'),
                ];
            }
            $out[$nr] = ['nr' => $nr, 'key' => $key, 'linie' => DEMO_LAEUFE[$key]['linie'], 'wagen' => DEMO_LAEUFE[$key]['wagen'], 'halte' => $halte];
        }
    }

    return $out;
}

/**
 * Abweichungen je Szenario, bezogen auf die n-te kommende Abfahrt ab 'bhf' in einer
 * Richtung (s nach Hamburg, n nach Norden): delay (Minuten), ausfall, gleis (heute
 * anderes Gleis), bis (endet dort), ab (startet statt am Hbf in Altona), ursache.
 */
function demo_abweichungen($szenario)
{
    if ($szenario === 'leicht') {
        return [
            ['bhf' => 'APD', 'richtung' => 's', 'n' => 1, 'delay' => 6, 'ursache' => 'Verspätung aus vorheriger Fahrt'],
            ['bhf' => 'AH', 'richtung' => 'n', 'n' => 0, 'delay' => 3],
        ];
    }
    if ($szenario === 'stoerung') {
        return [
            ['bhf' => 'APD', 'richtung' => 's', 'n' => 0, 'delay' => 14, 'ursache' => 'Signalstörung'],
            ['bhf' => 'APD', 'richtung' => 's', 'n' => 1, 'ausfall' => true, 'ursache' => 'Signalstörung'],
            ['bhf' => 'APD', 'richtung' => 's', 'n' => 2, 'delay' => 4, 'gleis' => '1'],
            ['bhf' => 'APD', 'richtung' => 's', 'n' => 3, 'bis' => 'AP', 'ursache' => 'Signalstörung'],
            ['bhf' => 'AH', 'richtung' => 'n', 'n' => 0, 'delay' => 9, 'ursache' => 'Signalstörung'],
            ['bhf' => 'AH', 'richtung' => 'n', 'n' => 1, 'ab' => 'AA', 'ursache' => 'Signalstörung'],
            ['bhf' => 'AH', 'richtung' => 'n', 'n' => 2, 'delay' => 22, 'ursache' => 'Signalstörung'],
        ];
    }

    return [['bhf' => 'APD', 'richtung' => 's', 'n' => 2, 'delay' => 2]];
}

/** Ordnet die Abweichungen den Zugnummern zu: nr => abweichung. */
function demo_zuordnen(array $fahrten, array $abweichungen, DateTime $now)
{
    $out = [];
    foreach ($abweichungen as $a) {
        $kandidaten = [];
        foreach ($fahrten as $f) {
            if (substr($f['key'], -1) !== $a['richtung']) {
                continue;
            }
            foreach ($f['halte'] as $h) {
                if ($h['id'] === $a['bhf'] && $h['ab'] !== null && $h['ab'] >= $now) {
                    $kandidaten[] = [$h['ab'], $f['nr']];
                }
            }
        }
        usort($kandidaten, function ($x, $y) {
            return $x[0] <=> $y[0];
        });
        if (isset($kandidaten[$a['n']])) {
            $out[$kandidaten[$a['n']][1]] = $a;
        }
    }

    return $out;
}

/**
 * Quellen fuer t_fetch: die Tafel jedes Bahnhofs ('APD' => xml) und die Zugauskunft
 * jeder Fahrt ('zug:81871' => xml). Im Szenario bau fahren RB61 und RB71 ab/bis Altona.
 */
function demo_quellen($szenario, DateTime $now)
{
    $fahrten = demo_fahrten($now);
    $abw = demo_zuordnen($fahrten, demo_abweichungen($szenario), $now);
    $von = (clone $now)->modify('-45 minutes');
    $bis = (clone $now)->modify('+150 minutes');

    $tafeln = array_fill_keys(array_keys(DEMO_NAMEN), []);
    $quellen = [];
    foreach ($fahrten as $f) {
        if ($szenario === 'bau' && $f['linie'] !== 'RB 60') {
            $f['halte'] = demo_ueber_altona($f['halte'], substr($f['key'], -1));
        }
        $a = $abw[$f['nr']] ?? [];
        $delay = (int) ($a['delay'] ?? 0);
        $ausfall = !empty($a['ausfall']);
        $letzter = end($f['halte']);
        $ursache = (string) ($a['ursache'] ?? '');
        // Halte, die heute entfallen: alles hinter 'bis' bzw. Hbf und Dammtor bei Start in Altona
        $entfaellt = [];
        if (!empty($a['bis'])) {
            $nach = false;
            foreach ($f['halte'] as $h) {
                if ($nach) {
                    $entfaellt[$h['id']] = true;
                }
                $nach = $nach || $h['id'] === $a['bis'];
            }
        }
        $abAltona = ($a['ab'] ?? '') === 'AA';
        if ($abAltona) {
            $entfaellt['AH'] = true;
            $entfaellt['ADF'] = true;
        }
        foreach ($f['halte'] as $h) {
            if ($h['ab'] === null || $h['ab'] < $von || $h['ab'] > $bis || ($abAltona && $h['id'] === 'ADF')) {
                continue;
            }
            $weg = $ausfall || isset($entfaellt[$h['id']]);
            $tafeln[$h['id']][] = [
                'zeit' => $h['ab']->format('H:i'), 'zug' => $f['nr'], 'linie' => $f['linie'],
                'ziel' => [$letzter['id'], $letzter['name']], 'gleis' => $h['gleis'],
                'gleiswechsel' => $h['id'] === ($a['bhf'] ?? '') ? (string) ($a['gleis'] ?? '') : '',
                'ausfall' => $weg, 'prognosemin' => $weg ? 0 : $delay, 'prognose' => $delay ? '+ ' . $delay . ' Min' : 'pünktlich',
                'wagen' => $f['wagen'], 'zuglaufinfo' => (bool) $entfaellt, 'ursachetext' => $weg || $delay >= 5 ? $ursache : '',
            ];
        }
        $altonaStart = null;
        if ($abAltona) {
            // Ersatz: dieselbe Zugnummer startet in Altona, sechs Minuten nach der Hbf-Zeit
            $altonaStart = (clone $f['halte'][0]['ab'])->modify('+6 minutes');
            if ($altonaStart >= $von && $altonaStart <= $bis) {
                $tafeln['AA'][] = ['zeit' => $altonaStart->format('H:i'), 'zug' => $f['nr'], 'linie' => $f['linie'], 'ziel' => [$letzter['id'], $letzter['name']], 'gleis' => '10', 'wagen' => $f['wagen']];
            }
        }
        $halteXml = [];
        foreach ($f['halte'] as $h) {
            $opt = ['gleis' => $h['gleis'], 'an_min' => $delay, 'ab_min' => $delay];
            if (isset($entfaellt[$h['id']])) {
                $opt['ausfall'] = true;
            }
            if (($a['bis'] ?? '') === $h['id']) {
                $opt['ab_prog'] = 'Halt entfällt!'; // der Zug kommt dort an und wendet
            }
            $halteXml[] = [$h['id'], $h['name'], $h['an'] ? $h['an']->format('H:i') : '', $h['ab'] ? $h['ab']->format('H:i') : '', $opt];
            if ($altonaStart !== null && $h['id'] === 'ADF') {
                $halteXml[] = ['AA', DEMO_NAMEN['AA'], '', $altonaStart->format('H:i'), ['gleis' => '10']];
            }
        }
        $zuglauf = $entfaellt ? 'Ausfall: hält heute nicht in ' . implode(', ', array_map(function ($id) {
            return DEMO_NAMEN[$id];
        }, array_keys($entfaellt))) : '';
        // t_zug traegt das feste Datum der Tests ein, die Live-Karte rechnet aber mit dem Datum je Halt
        $quellen['zug:' . $f['nr']] = str_replace('<datum>15.10.2026</datum>', '<datum>' . $now->format('d.m.Y') . '</datum>', t_zug($f['nr'], $f['linie'], $ursache, $halteXml, $zuglauf));
    }
    foreach ($tafeln as $id => $rows) {
        usort($rows, function ($x, $y) {
            return strcmp($x['zeit'], $y['zeit']);
        });
        $quellen[$id] = t_tafel(DEMO_NAMEN[$id], $rows, $now);
    }

    return $quellen;
}

/** Baufahrplan: statt Dammtor und Hbf faehrt der Zug nach bzw. ab Altona (Fahrzeit ab Pinneberg wie die RB60). */
function demo_ueber_altona(array $halte, $richtung)
{
    $out = [];
    foreach ($halte as $h) {
        if ($h['id'] === 'ADF') {
            continue;
        }
        if ($h['id'] === 'AH') {
            $h['id'] = 'AA';
            $h['name'] = DEMO_NAMEN['AA'];
            $h['gleis'] = '9';
            if ($richtung === 's') {
                $h['an'] = (clone $h['an'])->modify('-6 minutes');
            } else {
                $h['ab'] = (clone $h['ab'])->modify('+5 minutes');
            }
        }
        $out[] = $h;
    }

    return $out;
}

function demo_iso($ts)
{
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

/** Datenpaket wie aus n8n (Stoerungen, Eilmeldungen, Sonderfahrplaene) samt beider Logbuecher: [data, log, eilLog]. */
function demo_data($szenario, $now)
{
    $uhr = function ($ts) {
        return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');
    };
    $tag = function ($ts, $format = 'Y-m-d') {
        return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format($format);
    };
    $stoerungen = [];
    $live = [];
    $historie = [];
    $log = [];
    $eilLog = [];
    $eilmeldung = function ($id, $titel, $ts, $kiTitel, $kiText, $weg = null) use (&$historie, &$eilLog, &$live) {
        $historie[] = ['id' => $id, 'title' => $titel, 'seen_at' => demo_iso($ts), 'ki_titel' => $kiTitel, 'ki_text' => $kiText];
        $eilLog[$id] = ['first_live' => demo_iso($ts), 'last_live' => demo_iso($weg ?? time()), 'verlauf' => [['t' => demo_iso($ts), 'title' => $titel, 'stand' => demo_iso($ts)]]];
        if ($weg !== null) {
            $eilLog[$id]['weg'] = demo_iso($weg);
        } else {
            $live[] = ['id' => $id, 'title' => $titel, 'stand' => demo_iso($ts), 'link' => 'https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/'];
        }
    };
    $aufgehoben = function ($key, $titel, $kiTitel, $kiText, $von, $bis, $einschraenkung = 'mittel') use (&$log) {
        $log[$key] = [
            'first_seen' => demo_iso($von), 'last_seen' => demo_iso($bis), 'key' => $key, 'titel' => $titel, 'text' => 'Originalmeldung der DB InfraGO.',
            'abschnitte' => [], 'wirkungen' => ['Verspätungen'], 'einschraenkung' => $einschraenkung, 'beginn' => demo_iso($von), 'ende' => demo_iso($bis),
            'ki_titel' => $kiTitel, 'ki_text' => $kiText, 'ki_folgen' => 'verspaetungen',
            'verlauf' => [['t' => demo_iso($von), 'titel' => $titel, 'text' => '', 'ki_titel' => $kiTitel, 'ki_text' => $kiText, 'ende' => demo_iso($bis)]],
        ];
    };

    // Rueckblick fuer Verlauf, Archiv und Statistik
    $aufgehoben('BZI_4711', 'Weichenstörung – Elmshorn', 'Weichenstörung in Elmshorn: Züge wenden vorzeitig', 'In Elmshorn war eine Weiche gestört. Einzelne Züge der RB61 und RB71 endeten schon in Elmshorn, die übrigen waren 10 bis 15 Minuten verspätet.', $now - 26 * 3600, $now - 23 * 3600);
    $aufgehoben('BZI_4688', 'Bahnübergangsstörung – Tornesch', 'Bahnübergang in Tornesch gestört', 'Am Bahnübergang in Tornesch fuhren die Züge langsamer, Verspätungen von etwa 5 Minuten.', $now - 3 * 86400, $now - 3 * 86400 + 2400, 'leicht');
    $aufgehoben('BZI_4590', 'Oberleitungsstörung – Hamburg-Eidelstedt', 'Oberleitung bei Eidelstedt beschädigt: Züge nur bis Pinneberg', 'Zwischen Pinneberg und Hamburg fuhren fast drei Stunden lang keine Züge der RB61 und RB71. Weiter ging es mit der S3.', $now - 9 * 86400, $now - 9 * 86400 + 10200, 'schwer');
    $eilmeldung('em-880101', 'Zugausfall RB 71', $now - 27 * 3600, 'RB71 um 11:17 ab Hamburg Hbf nach Wrist fällt aus', 'Grund ist ein kurzfristiger Personalausfall. Die nächste Fahrt folgt eine Stunde später.', $now - 25 * 3600);

    if ($szenario === 'ruhig' || $szenario === 'bau') {
        $eilmeldung('em-880155', 'Verspätungen RB 61', $now - 6 * 3600, 'RB61 am Morgen bis zu 15 Minuten verspätet', 'Nach einer Türstörung an einem Zug in Glückstadt.', $now - 5 * 3600);
    }
    if ($szenario === 'leicht') {
        // Vor 25 Minuten aufgehoben: steht noch eine Stunde unter "Aktuelle Meldungen"
        $aufgehoben('BZI_4799', 'Störung an einem Bahnübergang – Prisdorf', 'Bahnübergang in Prisdorf war gestört', 'Die Züge fuhren am Bahnübergang langsamer, einzelne Verspätungen bis 10 Minuten. Die Störung ist behoben.', $now - 95 * 60, $now - 25 * 60, 'leicht');
    }
    if ($szenario === 'stoerung') {
        $beginn = $now - 55 * 60;
        $ende = $now + 65 * 60;
        $st = [
            'key' => 'BZI_4802', 'titel' => 'Signalstörung – Tornesch', 'text' => 'Störung an einem Signal im Bf Tornesch. Züge werden zurückgehalten, es kommt zu Verspätungen und Teilausfällen.',
            'abschnitte' => [['von' => 'Tornesch', 'bis' => 'Elmshorn', 'strecke' => 1220]], 'wirkungen' => ['Verspätungen', 'Teilausfall'], 'einschraenkung' => 'mittel',
            'beginn' => demo_iso($beginn), 'ende' => demo_iso($ende), 'abgelaufen' => false, 'betriebsstellen' => ['Tornesch', 'Elmshorn'],
            'ki_titel' => 'Signalstörung bei Tornesch: Verspätungen und Ausfälle',
            'ki_text' => 'Zwischen Tornesch und Elmshorn ist ein Signal gestört. Die Züge der RB61 und RB71 fahren dort langsamer und sind 10 bis 20 Minuten verspätet. Einzelne Züge fallen aus oder enden schon in Pinneberg.',
            'ki_folgen' => 'ausfaelle',
        ];
        $stoerungen[] = $st;
        $stand = ['titel' => $st['titel'], 'text' => $st['text'], 'ki_titel' => $st['ki_titel']];
        $log[$st['key']] = ['first_seen' => demo_iso($beginn), 'last_seen' => demo_iso($now)] + $st + ['verlauf' => [
            ['t' => demo_iso($beginn), 'ki_text' => 'Zwischen Tornesch und Elmshorn ist ein Signal gestört. Die Züge fahren dort langsamer.', 'ende' => demo_iso($now + 5 * 60)] + $stand,
            ['t' => demo_iso($now - 20 * 60), 'ki_text' => $st['ki_text'], 'ende' => demo_iso($ende)] + $stand,
        ]];
        $eilmeldung('em-880201', 'Störung an einem Signal in Tornesch', $now - 38 * 60, 'Signalstörung in Tornesch: RB61 und RB71 verspätet, einzelne Ausfälle', 'Bis voraussichtlich ' . $uhr($ende) . ' Uhr. Einige Züge wenden in Pinneberg, von dort weiter mit der S3.');
    }

    // Sonderfahrplaene wie auf nordbahn.de: ein Dauerhinweis, einer ab uebermorgen, im Szenario bau einer, der gerade gilt
    $sonder = [
        ['id' => 'sev-932', 'title' => 'Gleis 13/14 im Hamburger Hauptbahnhof bis ' . $tag($now + 40 * 86400, 'd.m.Y') . ' nicht barrierefrei zu erreichen', 'von' => $tag($now - 60 * 86400), 'bis' => $tag($now + 40 * 86400), 'link' => 'https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-932/'],
        ['id' => 'sev-1031', 'title' => $tag($now + 2 * 86400, 'd.m.') . ' - ' . $tag($now + 4 * 86400, 'd.m.') . ': Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe (RB 61), Züge der RB 71 fahren planmäßig', 'von' => $tag($now + 2 * 86400), 'bis' => $tag($now + 4 * 86400), 'link' => 'https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-1031/'],
        ['id' => 'sev-1040', 'title' => $tag($now + 20 * 86400, 'd.m.') . ' - ' . $tag($now + 22 * 86400, 'd.m.') . ': Nächtliche Bauarbeiten, letzte Züge der RB 61 und RB 71 enden in Pinneberg', 'von' => $tag($now + 20 * 86400), 'bis' => $tag($now + 22 * 86400), 'link' => 'https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-1040/'],
    ];
    if ($szenario === 'bau') {
        $sonder[] = ['id' => 'sev-1025', 'title' => $tag($now - 2 * 86400, 'd.m.') . ' - ' . $tag($now + 5 * 86400, 'd.m.') . ': Alle Züge der Linien RB 61 und RB 71 fahren ab/bis Hamburg-Altona; Ersatzverkehr mit Bussen zwischen Elmshorn und Itzehoe', 'von' => $tag($now - 2 * 86400), 'bis' => $tag($now + 5 * 86400), 'link' => 'https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/sev-1025/'];
    }

    $data = [
        'received_at' => demo_iso($now - 40), 'generated_at' => demo_iso($now - 45),
        'quellen' => ['strecken_info_ok' => true, 'nordbahn_ok' => true],
        'stoerungen' => $stoerungen, 'eilmeldungen_live' => $live, 'sonderfahrplaene' => $sonder, 'eilmeldungen' => $historie,
    ];

    return [$data, $log, $eilLog];
}

/** S3-Stand eines Stadtbahnhofs im Format von gf_build (api/s3). */
function demo_s3($szenario, DateTime $now, $stadt = GF_STADT_DEFAULT)
{
    $st = GF_STADT[$stadt];
    $fahrzeit = ['altona' => 22, 'stadthausbruecke' => 29, 'jungfernstieg' => 31, 'hbf' => 34][$stadt];
    $liste = [];
    $erste = (clone $now)->setTime((int) $now->format('H'), 0, 0)->modify('+' . (intdiv((int) $now->format('i'), 10) * 10 + 4) . ' minutes');
    if ($erste < $now) {
        $erste->modify('+10 minutes');
    }
    foreach ([false, true] as $rueck) {
        for ($i = 0; $i < GF_PER_DIR; $i++) {
            $plan = (clone $erste)->modify('+' . ($i * 10 + ($rueck ? 3 : 0)) . ' minutes');
            $delay = $szenario === 'stoerung' ? [0, 6, 0, 8, 0, 5][$i] : ($szenario === 'leicht' && $i === 1 ? 3 : 0);
            $ausfall = $szenario === 'stoerung' && !$rueck && $i === 2;
            $an = (clone $plan)->modify('+' . $fahrzeit . ' minutes');
            $liste[] = [
                'zeit' => $plan->format('c'), 'ist' => (clone $plan)->modify('+' . $delay . ' minutes')->format('c'), 'linie' => 'S3',
                'ziel' => $rueck ? 'Pinneberg' : ($i % 2 ? 'Stade' : 'Neugraben'), 'ab' => $rueck ? $st['name'] : GF_PINNEBERG,
                'delay_min' => $delay, 'ausfall' => $ausfall, 'gleis' => $rueck ? '2' : '1', 'extra' => false,
                'an' => $ausfall ? null : ['name' => $rueck ? GF_PINNEBERG : $st['kurz'], 'zeit' => $an->format('c'), 'ist' => (clone $an)->modify('+' . $delay . ' minutes')->format('c'), 'verspaetung' => $delay, 'ausfall' => false],
            ];
        }
    }
    $ts = $now->getTimestamp();
    $meldungen = [];
    if ($szenario === 'stoerung') {
        $meldungen[] = ['id' => 'HVV-55012', 'version' => 2, 'titel' => 'S3: Verspätungen nach Polizeieinsatz in Stellingen', 'text' => 'Wegen eines Polizeieinsatzes in Stellingen kommt es auf der Linie S3 zwischen Pinneberg und Altona zu Verspätungen von bis zu 10 Minuten.', 'geplant' => false, 'aktiv' => true, 'von' => demo_iso($ts - 25 * 60), 'bis' => demo_iso($ts + 50 * 60), 'linien' => ['S3'], 'stand' => demo_iso($ts - 10 * 60), 'grund' => 'POLICE_OPERATION', 'link' => 'https://www.hvv.de/de/fahrplaene/aktuelle-meldungen'];
    }
    $meldungen[] = ['id' => 'HVV-54001', 'version' => 1, 'titel' => 'S3: Bauarbeiten in den Nächten zum Wochenende', 'text' => 'Zwischen Elbgaustraße und Pinneberg fahren nachts Busse.', 'geplant' => true, 'aktiv' => true, 'von' => demo_iso($ts - 86400), 'bis' => demo_iso($ts + 3 * 86400), 'linien' => ['S3'], 'stand' => demo_iso($ts - 86400), 'grund' => 'CONSTRUCTION_SITE', 'link' => ''];

    return [
        'ok' => true, 'fetched_at' => $now->format('c'), 'stadt' => ['id' => $stadt] + $st,
        'quellen' => ['hin' => true, 'rueck' => true, 'meldungen' => true],
        's3' => gf_status($liste, $meldungen, $ts) + ['abfahrten' => $liste, 'meldungen' => $meldungen],
    ];
}

/**
 * DB Regio eines Heimatbahnhofs im Format von gf_regio_build (api/regio). In der Demo
 * halten RE nur in Elmshorn: RE7 zum und vom Hbf, RE6 nach und ab Altona.
 */
function demo_regio($szenario, DateTime $now, $heimat)
{
    $zeile = function ($linie, $ziel, $ab, DateTime $plan, $delay, $gleis, array $halte) {
        $lauf = [];
        foreach ($halte as [$name, $min, $gl, $istHeimat]) {
            $zeit = (clone $plan)->modify('+' . $min . ' minutes');
            $lauf[] = ['id' => '', 'name' => $name, 'zeit' => $zeit->format('H:i'), 'ist' => $delay ? (clone $zeit)->modify('+' . $delay . ' minutes')->format('H:i') : null, 'verspaetung' => $delay, 'gleis' => $gl, 'ausfall' => false, 'heimat' => $istHeimat];
        }

        return [
            'zug' => '', 'zeit' => $plan->format('c'), 'zeit_plan' => null, 'ist' => (clone $plan)->modify('+' . $delay . ' minutes')->format('c'), 'verspaetung' => $delay,
            'linie' => $linie, 'ziel' => $ziel, 'gleis' => $gleis, 'gleis_plan' => null, 'ausfall' => false, 'bus' => false, 'hinweis' => '', 'wagen' => 0,
            'ab' => $ab, 'ersatz' => null, 'teil' => null, 'ersatz_fuer' => null, 'dammtor' => null, 'quelle' => 'hvv', 'lauf' => $lauf,
        ];
    };
    $hin = [];
    $rueck = [];
    if ($heimat === 'AEL') {
        $stunde = (clone $now)->setTime((int) $now->format('H'), 0, 0);
        for ($h = 0; $h <= 2; $h++) {
            $delay = $szenario === 'stoerung' && $h === 0 ? 7 : 0;
            $basis = (clone $stunde)->modify('+' . $h . ' hours');
            $hin[] = $zeile('RE7', 'Hamburg Hbf', '', (clone $basis)->modify('+12 minutes'), $delay, '4', [['Elmshorn', 0, '4', true], ['Hamburg Dammtor', 22, '4', false], ['Hamburg Hbf', 26, '12', false]]);
            $hin[] = $zeile('RE6', 'Hamburg-Altona', '', (clone $basis)->modify('+41 minutes'), 0, '4', [['Elmshorn', 0, '4', true], ['Pinneberg', 9, '3', false], ['Hamburg-Altona', 24, '8', false]]);
            $rueck[] = $zeile('RE7', 'Kiel Hbf', 'Hbf', (clone $basis)->modify('+22 minutes'), $delay, '12', [['Hamburg Hbf', 0, '12', false], ['Hamburg Dammtor', 4, '3', false], ['Elmshorn', 27, '3', true], ['Neumünster', 55, '2', false], ['Kiel Hbf', 77, '5', false]]);
            $rueck[] = $zeile('RE6', 'Westerland (Sylt)', 'Altona', (clone $basis)->modify('+33 minutes'), 0, '8', [['Hamburg-Altona', 0, '8', false], ['Pinneberg', 14, '2', false], ['Elmshorn', 24, '3', true], ['Glückstadt', 38, '2', false], ['Itzehoe', 52, '1', false]]);
        }
        $kommend = function (array $rows) use ($now) {
            $rows = array_values(array_filter($rows, function ($d) use ($now) {
                return $d['ist'] >= $now->format('c');
            }));
            usort($rows, function ($a, $b) {
                return strcmp($a['ist'], $b['ist']);
            });

            return array_slice($rows, 0, GF_REGIO_PER_DIR);
        };
        $hin = $kommend($hin);
        $rueck = $kommend($rueck);
    }

    return [
        'ok' => true, 'fetched_at' => $now->format('c'), 'heimat' => ['id' => $heimat, 'name' => NORDBAHN_BAHNHOEFE[$heimat]['name']],
        'linien' => GF_REGIO_LINIEN, 'quellen' => ['hin' => true, 'rueck' => true], 'richtungen' => ['hin' => $hin, 'rueck' => $rueck],
    ];
}

/**
 * Zuglog der letzten 14 Tage fuer den Vorgabe-Bahnhof, damit Statistik und die Suche
 * unter "Meine Zuege" etwas kennen: je Tag vier Staende (frueh, vormittags,
 * nachmittags, abends), an einzelnen Tagen mit Verspaetungen oder einer Stoerung.
 */
function demo_zuglog(DateTime $now)
{
    if (count(glob(NB_ZUGLOG_DIR . '/*.json') ?: []) >= 10) {
        return;
    }
    for ($tag = 14; $tag >= 1; $tag--) {
        $szenario = $tag % 6 === 0 ? 'stoerung' : ($tag % 3 === 0 ? 'leicht' : 'ruhig');
        foreach ([6, 8, 16, 18] as $stunde) {
            $dann = (clone $now)->modify('-' . $tag . ' days')->setTime($stunde, 5, 0);
            @unlink(ABF_ZUG_CACHE);
            $result = abf_build(t_fetch(demo_quellen($szenario, $dann)), NORDBAHN_HEIMAT_DEFAULT, $dann);
            if ($result !== null) {
                zl_log($result, NORDBAHN_HEIMAT_DEFAULT, $dann->getTimestamp());
            }
        }
    }
}

/** Schreibt alle Cache-Dateien fuer den Stand "jetzt". */
function demo_seed($szenario, ?DateTime $now = null)
{
    $now = $now ?? new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $ts = $now->getTimestamp();
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    [$data, $log, $eilLog] = demo_data($szenario, $ts);
    nordbahn_write_json(NORDBAHN_DATA_FILE, $data);
    nordbahn_write_json(NORDBAHN_LOG_FILE, $log);
    nordbahn_write_json(NORDBAHN_EIL_LOG_FILE, $eilLog);

    demo_zuglog($now);
    @unlink(ABF_ZUG_CACHE);
    $fetch = t_fetch(demo_quellen($szenario, $now));
    foreach (array_keys(NORDBAHN_BAHNHOEFE) as $bhf) {
        $result = abf_build($fetch, $bhf, $now);
        if ($result !== null) {
            nordbahn_write_json(NORDBAHN_CACHE_DIR . '/abfahrten-' . $bhf . '.json', $result);
            zl_log($result, $bhf, $ts);
        }
    }
    foreach (array_keys(GF_STADT) as $stadt) {
        nordbahn_write_json(gf_cache_file($stadt), demo_s3($szenario, $now, $stadt));
    }
    foreach (array_keys(GF_REGIO_HEIMAT) as $bhf) {
        nordbahn_write_json(gf_regio_cache_file($bhf), demo_regio($szenario, $now, $bhf));
    }
    $karte = ka_build($fetch, $now, nordbahn_read_json(KA_CACHE));
    if ($karte !== null) {
        nordbahn_write_json(KA_CACHE, $karte);
    }
    file_put_contents(NORDBAHN_CACHE_DIR . '/demo-stand', $szenario);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $szenario = $argv[1] ?? demo_szenario();
    if (!in_array($szenario, DEMO_SZENARIEN, true)) {
        fwrite(STDERR, 'Unbekanntes Szenario. Möglich: ' . implode(', ', DEMO_SZENARIEN) . "\n");
        exit(1);
    }
    demo_seed($szenario);
}
