<?php
/**
 * Fahrplan je Bahnhof fuer die Suche unter "Meine Zuege": alle Zuege, die die
 * Seite in den letzten FP_TAGE Tagen beobachtet hat, mit Planzeit, Linie, Ziel
 * und den Wochentagen, an denen sie fuhren.
 *
 * URL: /api/fahrplan?bhf=APD
 * Antwort: ok, heimat {id, name}, tage (Fenster), hin[], rueck[] je
 *          {zug, linie, zeit "HH:MM", ziel, ab, tage [1 = Montag …]}
 *
 * Quelle ist das Zuglog (api/zuglog.php). Die Rueckrichtung kommt von den
 * Hamburger Tafeln und gilt fuer alle Bahnhoefe, die der Zug erreicht; die
 * Hinrichtung kennt die Seite nur fuer Bahnhoefe, die abgefragt werden
 * (Prisdorf durchgehend, andere, solange jemand sie offen hat oder dort einen
 * Zug gemerkt hat). Je Bahnhof FP_TTL Sekunden gecacht.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/zuglog.php';
if (!defined('NORDBAHN_ABF_LIB')) {
    define('NORDBAHN_ABF_LIB', true); // nur die Funktionen (abf_erreicht), kein Endpunkt
}
require_once __DIR__ . '/abfahrten.php';

const FP_TAGE = 14;    // Beobachtungsfenster in Tagen
const FP_TTL  = 1800;  // Sekunden, die ein Fahrplan je Bahnhof gilt
const FP_AB_RANG = ['AH' => 0, 'AA' => 1, 'AP' => 2, 'AEL' => 3, 'ADF' => 4]; // bei gleich oft gesehen: Hbf vor Altona vor Dammtor

/** Bahnhofs-ID zu einem Zielnamen der Tafel ("Itzehoe", "Wrist"), sonst ''. */
function fp_ziel_id($ziel)
{
    foreach (NORDBAHN_BAHNHOEFE as $id => $b) {
        if (strcasecmp(trim((string) $ziel), $b['name']) === 0) {
            return $id;
        }
    }

    return '';
}

/**
 * Baut die Zuglisten aus Tageslogs (zl_lesen). Je Zugnummer zaehlt der
 * Startbahnhof, an dem er am oeftesten gesehen wurde (Ersatz ab Altona oder
 * die Dammtor-Zeile nach der Abfahrt am Hbf zaehlen so nicht als eigener Zug).
 * Bekommt ein Zug nach dem Fahrplanwechsel eine neue Nummer, bleibt nur die
 * zuletzt gesehene. Reine Funktion, damit sie sich testen laesst.
 */
function fp_aus_logs(array $logs, $heimat)
{
    $zuege = ['hin' => [], 'rueck' => []];
    ksort($logs);
    foreach ($logs as $tag => $eintraege) {
        $wt = (int) (DateTime::createFromFormat('!Y-m-d', $tag, new DateTimeZone('Europe/Berlin')) ?: new DateTime())->format('N');
        foreach (is_array($eintraege) ? $eintraege : [] as $e) {
            if (!is_array($e) || empty($e['zug']) || empty($e['plan']) || !in_array($e['dir'] ?? '', ['hin', 'rueck'], true)) {
                continue;
            }
            $dir = $e['dir'];
            if ($dir === 'hin' && ($e['station'] ?? '') !== $heimat) {
                continue;
            }
            if ($dir === 'rueck' && !abf_erreicht(fp_ziel_id($e['ziel'] ?? ''), $heimat)) {
                continue;
            }
            $station = (string) ($e['station'] ?? '');
            $z = &$zuege[$dir][$e['zug']][$station];
            if (!is_array($z)) {
                $z = ['n' => 0, 'tage' => []];
            }
            $z['n']++;
            $z['tage'][$wt] = $wt;
            // Stammdaten vom juengsten Tag
            $z['zug'] = (string) $e['zug'];
            $z['linie'] = (string) ($e['linie'] ?? '');
            $z['zeit'] = (string) $e['plan'];
            $z['ziel'] = (string) ($e['ziel'] ?? '');
            $z['ab'] = $dir === 'rueck' ? (string) ($e['ab'] ?? '') : '';
            $z['zuletzt'] = $tag;
            $z['rang'] = FP_AB_RANG[$station] ?? 9;
            unset($z);
        }
    }

    $out = [];
    foreach ($zuege as $dir => $liste) {
        // Je Zugnummer der Startbahnhof, an dem er am oeftesten fuhr
        $je = [];
        foreach ($liste as $stationen) {
            usort($stationen, function ($a, $b) {
                return [$b['n'], $a['rang']] <=> [$a['n'], $b['rang']];
            });
            $je[] = $stationen[0];
        }
        // Gleiche Linie, Zeit und Startbahnhof unter zwei Nummern: die zuletzt gesehene gilt
        $neu = [];
        foreach ($je as $z) {
            $k = $z['linie'] . '|' . $z['zeit'] . '|' . $z['ab'];
            if (!isset($neu[$k]) || $z['zuletzt'] > $neu[$k]['zuletzt']) {
                $neu[$k] = $z;
            }
        }
        $neu = array_values($neu);
        usort($neu, function ($a, $b) {
            return [$a['zeit'], $a['ab']] <=> [$b['zeit'], $b['ab']];
        });
        $out[$dir] = array_map(function ($z) {
            $tage = array_values($z['tage']);
            sort($tage);

            return ['zug' => $z['zug'], 'linie' => $z['linie'], 'zeit' => $z['zeit'], 'ziel' => $z['ziel'], 'ab' => $z['ab'], 'tage' => $tage];
        }, $neu);
    }

    return $out;
}

/** Fahrplan fuer einen Bahnhof aus dem Cache (FP_TTL), sonst frisch aus dem Zuglog. */
function fp_cached($heimat, $now = null)
{
    $now = $now ?? time();
    $file = NORDBAHN_CACHE_DIR . '/fahrplan-' . $heimat . '.json';
    $cached = nordbahn_read_json($file);
    if (is_array($cached) && isset($cached['t']) && $now - (int) $cached['t'] < FP_TTL) {
        return $cached;
    }
    $result = [
        'ok'     => true,
        't'      => $now,
        'heimat' => ['id' => $heimat, 'name' => NORDBAHN_BAHNHOEFE[$heimat]['name']],
        'tage'   => FP_TAGE,
    ] + fp_aus_logs(zl_lesen(FP_TAGE, $now), $heimat);
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    nordbahn_write_json($file, $result);

    return $result;
}

// Als Bibliothek eingebunden (Tests): kein Endpunkt
if (PHP_SAPI === 'cli') {
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
header('X-Robots-Tag: noindex');

$heimat = isset($_GET['bhf']) ? strtoupper(trim((string) $_GET['bhf'])) : NORDBAHN_HEIMAT_DEFAULT;
if (!isset(NORDBAHN_BAHNHOEFE[$heimat])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unbekannter Bahnhof.', 'bahnhoefe' => array_keys(NORDBAHN_BAHNHOEFE)]);
    exit;
}
echo json_encode(fp_cached($heimat), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
