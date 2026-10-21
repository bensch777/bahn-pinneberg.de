<?php
/**
 * Zuglog: Zuverlaessigkeit je Zug.
 *
 * Die Abfahrten werden ohnehin jede Minute geholt. Jede Aufbereitung schreibt je
 * Tag, Bahnhof und Zugnummer den zuletzt beobachteten Zustand in cache/zuglog/
 * <Tag>.json: Verspaetung (letzte und hoechste), Ausfall, Teilausfall,
 * Gleiswechsel. Ein Zug verschwindet nach der Abfahrt aus der Tafel, der letzte
 * Stand davor ist also das Ergebnis des Tages. Ein Ausfall bleibt fuenf Minuten
 * stehen und wird so sicher gesehen.
 *
 * Beobachtet wird, was abgefragt wird: der Vorgabe-Bahnhof alle zwei Minuten
 * ueber den n8n-Push, andere Bahnhoefe, solange jemand die Seite offen hat. Die
 * Hamburger Abfahrten (Hbf, Altona, Dammtor) sind fuer alle Heimatbahnhoefe
 * dieselben und werden daher unter dem Hamburger Bahnhof gefuehrt.
 *
 * Die Auswertung (zl_statistik) fasst die letzten NB_ZUGLOG_TAGE Tage je Zug
 * zusammen und wird NB_ZUGSTAT_TTL Sekunden gecacht.
 */

const NB_ZUGLOG_DIR       = NORDBAHN_CACHE_DIR . '/zuglog';
const NB_ZUGLOG_KEEP_DAYS = 60;                              // so lange bleiben die Tagesdateien
const NB_ZUGLOG_TAGE      = 30;                              // Auswertungsfenster
const NB_ZUGSTAT_FILE     = NORDBAHN_CACHE_DIR . '/zugstat.json';
const NB_ZUGSTAT_TTL      = 1800;                            // Sekunden, die die Auswertung gilt
const NB_ZUGLOG_MIN_TAGE  = 5;                               // ab so vielen Beobachtungstagen gibt es eine Aussage
const NB_ZUG_SPAET        = 5;                               // Minuten, ab denen ein Zug als verspaetet zaehlt

/** Bahnhof, an dem die Zeile beobachtet wird: Heimat (hin) oder Hamburger Startbahnhof (rueck). */
function zl_station($dir, array $row, $heimat)
{
    if ($dir === 'hin') {
        return $heimat;
    }
    $map = ['Hbf' => 'AH', 'Altona' => 'AA', 'Dammtor' => 'ADF', 'Pinneberg' => 'AP', 'Elmshorn' => 'AEL'];

    return $map[$row['ab'] ?? ''] ?? 'AH';
}

/** Kalendertag (Berlin) und Uhrzeit "HH:MM" eines ISO-Zeitstempels. */
function zl_tag_uhr($iso)
{
    $ts = $iso ? strtotime((string) $iso) : false;
    if ($ts === false) {
        return [null, ''];
    }
    $d = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'));

    return [$d->format('Y-m-d'), $d->format('H:i')];
}

/**
 * Arbeitet die Zeilen eines Abfahrten-Ergebnisses in die Tageslogs ein.
 * $logs ist ['YYYY-MM-DD' => [key => eintrag]]; zurueck kommen nur die
 * geaenderten Tage. Reine Funktion, damit sie sich testen laesst.
 */
function zl_merge(array $logs, array $result, $heimat, $now)
{
    $geaendert = [];
    foreach (['hin', 'rueck'] as $dir) {
        foreach ($result['richtungen'][$dir] ?? [] as $row) {
            if (!is_array($row) || ($row['zug'] ?? '') === '') {
                continue;
            }
            // Planzeit: bei "beginnt erst unterwegs" steht die Hamburger Planzeit in zeit_plan
            [$tag, $plan] = zl_tag_uhr($row['zeit_plan'] ?? $row['zeit'] ?? '');
            if ($tag === null) {
                continue;
            }
            $station = zl_station($dir, $row, $heimat);
            $key = $station . '|' . $dir . '|' . $row['zug'];
            $alt = $logs[$tag][$key] ?? null;
            $versp = (int) ($row['verspaetung'] ?? 0);
            // Planmaessige Kurzlaeufe (nachts nur bis Pinneberg) sind kein Teilausfall
            $teil = !empty($row['teil']['art']) && empty($row['teil']['plan']) ? (string) $row['teil']['art'] : '';
            $eintrag = [
                'zug'     => (string) $row['zug'],
                'linie'   => (string) ($row['linie'] ?? ''),
                'ziel'    => (string) ($row['ziel'] ?? ''),
                'ab'      => (string) ($row['ab'] ?? ''),
                'station' => $station,
                'dir'     => $dir,
                'plan'    => $plan,
                'versp'   => $versp,
                'max'     => max($versp, (int) ($alt['max'] ?? 0)),
                'ausfall' => !empty($row['ausfall']),
                'teil'    => $teil,
                'gleis'   => !empty($row['gleis_plan']) && $row['gleis_plan'] !== ($row['gleis'] ?? null),
                'n'       => (int) ($alt['n'] ?? 0) + 1,
                't'       => (int) $now,
            ];
            $logs[$tag][$key] = $eintrag;
            $geaendert[$tag] = $logs[$tag];
        }
    }

    return $geaendert;
}

/** Schreibt die Beobachtungen eines Ergebnisses ins Log (Dateien je Tag). */
function zl_log(array $result, $heimat, $now = null)
{
    if (empty($result['ok']) || empty($result['richtungen'])) {
        return;
    }
    $now = $now ?? time();
    $tage = [];
    foreach (['hin', 'rueck'] as $dir) {
        foreach ($result['richtungen'][$dir] ?? [] as $row) {
            [$tag] = zl_tag_uhr($row['zeit_plan'] ?? $row['zeit'] ?? '');
            if ($tag !== null) {
                $tage[$tag] = true;
            }
        }
    }
    if (!$tage) {
        return;
    }
    if (!is_dir(NB_ZUGLOG_DIR)) {
        @mkdir(NB_ZUGLOG_DIR, 0775, true);
    }
    $logs = [];
    foreach (array_keys($tage) as $tag) {
        $alt = nordbahn_read_json(NB_ZUGLOG_DIR . '/' . $tag . '.json');
        $logs[$tag] = is_array($alt) ? $alt : [];
    }
    foreach (zl_merge($logs, $result, $heimat, $now) as $tag => $eintraege) {
        nordbahn_write_json(NB_ZUGLOG_DIR . '/' . $tag . '.json', $eintraege);
    }
}

/**
 * Fasst Tageslogs je Zug zusammen: Beobachtungstage, Ausfaelle, Teilausfaelle,
 * Verspaetungen ab NB_ZUG_SPAET Minuten, mittlere und hoechste Verspaetung
 * (jeweils ohne ausgefallene Tage). Ergebnis: [station][dir][zug] => Kennzahlen.
 */
function zl_auswertung(array $logs)
{
    $zuege = [];
    ksort($logs);
    foreach ($logs as $tag => $eintraege) {
        foreach ($eintraege as $e) {
            if (!is_array($e) || empty($e['zug']) || empty($e['station']) || empty($e['dir'])) {
                continue;
            }
            $z = &$zuege[$e['station']][$e['dir']][$e['zug']];
            if (!is_array($z)) {
                $z = ['zug' => $e['zug'], 'linie' => '', 'ziel' => '', 'ab' => '', 'plan' => '', 'tage' => 0, 'ausfaelle' => 0, 'teil' => 0, 'spaet' => 0, 'gleis' => 0, 'versp_summe' => 0, 'max' => 0, 'zuletzt' => ''];
            }
            // Stammdaten vom juengsten Tag
            $z['linie'] = (string) ($e['linie'] ?? $z['linie']);
            $z['ziel'] = (string) ($e['ziel'] ?? $z['ziel']);
            $z['ab'] = (string) ($e['ab'] ?? $z['ab']);
            $z['plan'] = (string) ($e['plan'] ?? $z['plan']);
            $z['zuletzt'] = $tag;
            $z['tage']++;
            if (!empty($e['ausfall'])) {
                $z['ausfaelle']++;
                continue;
            }
            if (!empty($e['teil'])) {
                $z['teil']++;
            }
            if (!empty($e['gleis'])) {
                $z['gleis']++;
            }
            $v = (int) ($e['versp'] ?? 0);
            if ($v >= NB_ZUG_SPAET) {
                $z['spaet']++;
            }
            $z['versp_summe'] += max(0, $v);
            $z['max'] = max($z['max'], (int) ($e['max'] ?? $v));
            unset($z);
        }
    }
    foreach ($zuege as &$dirs) {
        foreach ($dirs as &$liste) {
            foreach ($liste as &$z) {
                $gefahren = $z['tage'] - $z['ausfaelle'];
                $z['schnitt'] = $gefahren > 0 ? round($z['versp_summe'] / $gefahren, 1) : 0;
                $z['ausfallquote'] = $z['tage'] > 0 ? round($z['ausfaelle'] / $z['tage'], 3) : 0;
                $z['spaetquote'] = $gefahren > 0 ? round($z['spaet'] / $gefahren, 3) : 0;
                unset($z['versp_summe']);
            }
            unset($z);
            uasort($liste, function ($a, $b) {
                return strcmp($a['plan'], $b['plan']);
            });
        }
        unset($liste);
    }
    unset($dirs);

    return $zuege;
}

/** Liest die Tageslogs der letzten $tage Tage (Berliner Kalendertage, heute eingeschlossen). */
function zl_lesen($tage, $now = null)
{
    $now = $now ?? time();
    $tz = new DateTimeZone('Europe/Berlin');
    $logs = [];
    for ($i = 0; $i < $tage; $i++) {
        $tag = (new DateTime('@' . ($now - $i * 86400)))->setTimezone($tz)->format('Y-m-d');
        $e = nordbahn_read_json(NB_ZUGLOG_DIR . '/' . $tag . '.json');
        if (is_array($e) && $e) {
            $logs[$tag] = $e;
        }
    }

    return $logs;
}

/** Loescht Tagesdateien, die aelter als NB_ZUGLOG_KEEP_DAYS sind. */
function zl_aufraeumen($now = null)
{
    $now = $now ?? time();
    $grenze = (new DateTime('@' . ($now - NB_ZUGLOG_KEEP_DAYS * 86400)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        if (basename($f, '.json') < $grenze) {
            @unlink($f);
        }
    }
}

/**
 * Auswertung der letzten NB_ZUGLOG_TAGE Tage, gecacht. Ergebnis:
 * ['stand' => ts, 'tage' => N, 'seit' => 'Y-m-d'|null, 'zuege' => [...]].
 */
function zl_statistik($now = null, $frisch = false)
{
    $now = $now ?? time();
    $cache = $frisch ? null : nordbahn_read_json(NB_ZUGSTAT_FILE);
    if (is_array($cache) && isset($cache['stand'], $cache['zuege']) && $now - (int) $cache['stand'] < NB_ZUGSTAT_TTL) {
        return $cache;
    }
    $logs = zl_lesen(NB_ZUGLOG_TAGE, $now);
    $tage = array_keys($logs);
    sort($tage);
    $stat = ['stand' => $now, 'tage' => NB_ZUGLOG_TAGE, 'seit' => $tage ? $tage[0] : null, 'beobachtete_tage' => count($tage), 'zuege' => zl_auswertung($logs)];
    nordbahn_write_json(NB_ZUGSTAT_FILE, $stat);
    zl_aufraeumen($now);

    return $stat;
}
