<?php
/**
 * Fahrplan je Bahnhof fuer die Suche unter "Meine Zuege": alle Zuege, die die
 * Seite in den letzten FP_TAGE Tagen beobachtet hat, mit Planzeit, Linie, Ziel
 * und den Wochentagen, an denen sie fuhren.
 *
 * URL: /api/fahrplan?bhf=APD
 * Antwort: ok, heimat {id, name}, tage (Fenster), hin[], rueck[] je
 *          {zug, nummern [zug, Varianten …], linie, zeit "HH:MM", ziel, ab,
 *           tage [1 = Montag …], varianten [{zug, zeit, ziel, ab}]}
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
const FP_VARIANTE_MIN = 10; // Minuten, die zwei Varianten desselben Pendelzugs hoechstens auseinanderliegen
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
 *
 * Varianten desselben Pendelzugs werden ein Eintrag: Baufahrplan (08:18 nach
 * Altona statt 08:17 zum Hbf) oder Fahrplanwechsel bringen eine neue
 * Zugnummer. Zwei Nummern gelten als Varianten, wenn Linie und Richtung
 * gleich sind, die Planzeiten hoechstens FP_VARIANTE_MIN Minuten auseinander
 * liegen, sie nie am selben Tag fuhren und beide werktags oder beide nur am
 * Wochenende fahren (ein Werktags- und ein Wochenendzug bleiben getrennt). Der zuletzt gesehene
 * fuehrt, die anderen stehen unter nummern und varianten. Reine Funktion,
 * damit sie sich testen laesst.
 */
function fp_aus_logs(array $logs, $heimat)
{
    $zuege = ['hin' => [], 'rueck' => []];
    $daten = ['hin' => [], 'rueck' => []]; // Tage je Zugnummer, an denen sie fuhr
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
            $daten[$dir][$e['zug']][$tag] = true;
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
        // Varianten zusammenfassen, der zuletzt gesehene Zug fuehrt
        usort($je, function ($a, $b) {
            return [$b['zuletzt'], $a['zeit']] <=> [$a['zuletzt'], $b['zeit']];
        });
        $gruppen = [];
        foreach ($je as $z) {
            $z['daten'] = $daten[$dir][$z['zug']] ?? [];
            foreach ($gruppen as &$g) {
                if (fp_variante($g, $z)) {
                    $g['varianten'][] = $z;
                    $g['daten'] += $z['daten'];
                    $g['tage'] += $z['tage'];
                    continue 2;
                }
            }
            unset($g);
            $gruppen[] = $z + ['varianten' => []];
        }
        unset($g);
        usort($gruppen, function ($a, $b) {
            return [$a['zeit'], $a['ab']] <=> [$b['zeit'], $b['ab']];
        });
        $out[$dir] = array_map(function ($g) {
            $tage = array_values($g['tage']);
            sort($tage);

            return [
                'zug'       => $g['zug'],
                'nummern'   => array_merge([$g['zug']], array_column($g['varianten'], 'zug')),
                'linie'     => $g['linie'],
                'zeit'      => $g['zeit'],
                'ziel'      => $g['ziel'],
                'ab'        => $g['ab'],
                'tage'      => $tage,
                'varianten' => array_map(function ($v) {
                    return ['zug' => $v['zug'], 'zeit' => $v['zeit'], 'ziel' => $v['ziel'], 'ab' => $v['ab']];
                }, $g['varianten']),
            ];
        }, $gruppen);
    }

    return $out;
}

/** Minuten seit Mitternacht zu "HH:MM". */
function fp_minuten($uhr)
{
    return preg_match('/^(\d{1,2}):(\d{2})$/', (string) $uhr, $m) ? (int) $m[1] * 60 + (int) $m[2] : -9999;
}

/**
 * Ist $z eine Variante des Pendelzugs $g (Gruppe mit gesammelten Tagen)?
 * Gleiche Linie, Planzeit hoechstens FP_VARIANTE_MIN Minuten daneben (zum
 * Fuehrenden und zu jeder Variante), nie am selben Tag, beide werktags oder
 * beide nur am Wochenende. Gemeinsame Wochentage reichen nicht als Bedingung:
 * beginnt ein Baufahrplan am Mittwoch, fuhr der alte Zug nur Mo-Di.
 */
function fp_variante(array $g, array $z)
{
    $werktags = function (array $tage) {
        return (bool) array_intersect_key($tage, [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1]);
    };
    if ($g['linie'] !== $z['linie'] || array_intersect_key($g['daten'], $z['daten']) || $werktags($g['tage']) !== $werktags($z['tage'])) {
        return false;
    }
    foreach (array_merge([$g], $g['varianten']) as $v) {
        if (abs(fp_minuten($v['zeit']) - fp_minuten($z['zeit'])) > FP_VARIANTE_MIN) {
            return false;
        }
    }

    return true;
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
