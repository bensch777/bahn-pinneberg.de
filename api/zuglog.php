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
 * DB-Regio-Zuege (RE6, RE7, RE70 aus api/regio, quelle hvv) haben keine
 * Zugnummer. Sie werden unter "Linie Planzeit" gefuehrt (z. B. "RE70 07:12"),
 * tragen quelle=hvv und in der Rueckrichtung unter bis die Heimatbahnhoefe, an
 * denen der RE laut Geofox haelt. Laufend beobachtet wird Elmshorn
 * (ZL_DB_LAUFEND, mit dem n8n-Push), dort halten alle RE.
 *
 * Die Auswertung fuer die Statistikseite (zl_dashboard) filtert nach Bahnhof,
 * Betreiber, Richtung und Zeitraum, immer nur im Pendelfenster (ZL_PENDELFENSTER:
 * Mo-Fr, nach Hamburg morgens, zurueck abends), und wird je Filter
 * NB_ZUGSTAT_TTL Sekunden gecacht. Nordbahn und DB Regio werden nie gemischt.
 */

const NB_ZUGLOG_DIR       = NORDBAHN_CACHE_DIR . '/zuglog';
const NB_ZUGLOG_KEEP_DAYS = 60;                              // so lange bleiben die Tagesdateien
const NB_ZUGLOG_TAGE      = 30;                              // Auswertungsfenster
const NB_ZUGSTAT_TTL      = 1800;                            // Sekunden, die eine Auswertung je Filter gilt
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
            if (!is_array($row)) {
                continue;
            }
            // Planzeit: bei "beginnt erst unterwegs" steht die Hamburger Planzeit in zeit_plan
            [$tag, $plan] = zl_tag_uhr($row['zeit_plan'] ?? $row['zeit'] ?? '');
            if ($tag === null) {
                continue;
            }
            $db = ($row['quelle'] ?? '') === 'hvv';
            $zug = (string) ($row['zug'] ?? '');
            if ($zug === '' && $db && ($row['linie'] ?? '') !== '') {
                $zug = (string) $row['linie'] . ' ' . $plan; // RE ohne Zugnummer: Linie und Planzeit
            }
            if ($zug === '') {
                continue;
            }
            $station = zl_station($dir, $row, $heimat);
            $key = $station . '|' . $dir . '|' . $zug;
            $alt = $logs[$tag][$key] ?? null;
            $versp = (int) ($row['verspaetung'] ?? 0);
            // Planmaessige Kurzlaeufe (nachts nur bis Pinneberg) sind kein Teilausfall
            $teil = !empty($row['teil']['art']) && empty($row['teil']['plan']) ? (string) $row['teil']['art'] : '';
            $eintrag = [
                'zug'     => $zug,
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
            if ($db) {
                $eintrag['quelle'] = 'hvv';
                if ($dir === 'rueck') {
                    // Heimatbahnhoefe, fuer die dieser RE ab Hamburg gemeldet wurde
                    $bis = array_values(array_unique(array_merge((array) ($alt['bis'] ?? []), [(string) $heimat])));
                    sort($bis);
                    $eintrag['bis'] = $bis;
                }
            }
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

/** Loescht Tagesdateien, die aelter als NB_ZUGLOG_KEEP_DAYS sind, und abgelaufene Auswertungen je Filter. */
function zl_aufraeumen($now = null)
{
    $now = $now ?? time();
    $grenze = (new DateTime('@' . ($now - NB_ZUGLOG_KEEP_DAYS * 86400)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    foreach (glob(NB_ZUGLOG_DIR . '/*.json') ?: [] as $f) {
        if (basename($f, '.json') < $grenze) {
            @unlink($f);
        }
    }
    foreach (glob(NORDBAHN_CACHE_DIR . '/zugstat*.json') ?: [] as $f) {
        if ($now - (int) @filemtime($f) > 86400) {
            @unlink($f);
        }
    }
}

/* ---------- Dashboard mit Filtern (statistik/) ---------- */

const ZL_ZEITRAEUME  = [7, 14, 30, 60];                         // waehlbare Zeitraeume in Tagen, hoechstens NB_ZUGLOG_KEEP_DAYS
const ZL_PENDELFENSTER = ['hin' => ['05:00', '10:00'], 'rueck' => ['15:00', '20:00']]; // Planabfahrt, nur Mo-Fr
const ZL_TOP         = 5;                                       // Zuege je Rangliste
const ZL_HAMBURG     = ['AH', 'AA', 'ADF'];                     // Hamburger Abfahrten der Rueckrichtung
const ZL_BETREIBER   = ['nordbahn' => 'Nordbahn (RB)', 'db' => 'DB Regio (RE)'];
const ZL_DB_BAHNHOEFE = ['AP', 'AEL', 'AGST', 'AIZ', 'AWST'];   // wie GF_REGIO_HEIMAT in api/geofox.php
const ZL_DB_LAUFEND  = 'AEL';                                    // laufend beobachteter RE-Bahnhof (alle RE halten)

/** Filter aus der Adresse, jeder Wert gegen die erlaubten geprueft. */
function zl_filter(array $get)
{
    $bhf = strtoupper(trim((string) ($get['bhf'] ?? '')));
    $tage = (int) ($get['tage'] ?? NB_ZUGLOG_TAGE);
    $bhf = isset(NORDBAHN_BAHNHOEFE[$bhf]) ? $bhf : NORDBAHN_HEIMAT_DEFAULT;
    $db = ($get['betreiber'] ?? '') === 'db' && in_array($bhf, ZL_DB_BAHNHOEFE, true);

    return [
        'bhf'      => $bhf,
        'betreiber' => $db ? 'db' : 'nordbahn',
        'richtung' => in_array($get['richtung'] ?? '', ['hin', 'rueck'], true) ? $get['richtung'] : 'beide',
        'tage'     => in_array($tage, ZL_ZEITRAEUME, true) ? $tage : NB_ZUGLOG_TAGE,
    ];
}

/** Werktag (Mo-Fr) nach Kalender, Feiertage zaehlen mit. */
function zl_werktag($tag)
{
    return (int) date('N', strtotime($tag . ' 12:00:00')) < 6;
}

/**
 * Passt ein Logeintrag eines Tages zum Filter? Nur im Pendelfenster, Nordbahn
 * und DB Regio nie gemischt. Rueckrichtung: nur Zuege, die den Bahnhof
 * erreichen (RE laut bis).
 */
function zl_passt(array $e, $tag, array $f)
{
    if (empty($e['zug']) || empty($e['dir']) || empty($e['station'])) {
        return false;
    }
    $db = ($e['quelle'] ?? '') === 'hvv';
    if ($db !== (($f['betreiber'] ?? 'nordbahn') === 'db')) {
        return false;
    }
    $fenster = ZL_PENDELFENSTER[$e['dir']] ?? null;
    if (!$fenster || !zl_werktag($tag) || ($e['plan'] ?? '') < $fenster[0] || ($e['plan'] ?? '') >= $fenster[1]) {
        return false;
    }
    if ($e['dir'] === 'hin') {
        return $f['richtung'] !== 'rueck' && $e['station'] === $f['bhf'];
    }
    if ($f['richtung'] === 'hin' || !in_array($e['station'], ZL_HAMBURG, true)) {
        return false;
    }
    if ($db) {
        return in_array($f['bhf'], (array) ($e['bis'] ?? []), true);
    }
    $ast = NORDBAHN_BAHNHOEFE[$f['bhf']]['ast'];
    $ziel = (string) ($e['ziel'] ?? '');

    return !($ast === 'itzehoe' && stripos($ziel, 'Wrist') !== false) && !($ast === 'wrist' && stripos($ziel, 'Itzehoe') !== false);
}

/** Ergebnis einer Fahrt: ausfall (ganz oder teilweise), spaet (ab NB_ZUG_SPAET) oder puenktlich. */
function zl_ergebnis(array $e)
{
    if (!empty($e['ausfall']) || !empty($e['teil'])) {
        return 'ausfall';
    }

    return (int) ($e['versp'] ?? 0) >= NB_ZUG_SPAET ? 'spaet' : 'puenktlich';
}

/**
 * Dashboard fuer einen Filter: Kennzahlen, Verlauf je Werktag (aelteste zuerst,
 * auch Tage ohne Beobachtung) und die Ranglisten der Zuege. Reine Funktion ueber
 * den Tageslogs, damit sie sich testen laesst.
 */
function zl_dashboard_rechnen(array $logs, array $f, $now)
{
    $tz = new DateTimeZone('Europe/Berlin');
    $k = ['fahrten' => 0, 'puenktlich' => 0, 'spaet' => 0, 'ausfall' => 0, 'teil' => 0, 'versp_summe' => 0, 'gefahren' => 0];
    $verlauf = [];
    $zuege = [];
    for ($i = $f['tage'] - 1; $i >= 0; $i--) {
        $tag = (new DateTime('@' . ($now - $i * 86400)))->setTimezone($tz)->format('Y-m-d');
        if (!zl_werktag($tag)) {
            continue;
        }
        $v = ['tag' => $tag, 'puenktlich' => 0, 'spaet' => 0, 'ausfall' => 0, 'beobachtet' => isset($logs[$tag])];
        foreach ($logs[$tag] ?? [] as $e) {
            if (!is_array($e) || !zl_passt($e, $tag, $f)) {
                continue;
            }
            $erg = zl_ergebnis($e);
            $v[$erg]++;
            $k[$erg]++;
            $k['fahrten']++;
            if (!empty($e['teil']) && empty($e['ausfall'])) {
                $k['teil']++;
            }
            if (empty($e['ausfall'])) {
                $k['gefahren']++;
                $k['versp_summe'] += max(0, (int) ($e['versp'] ?? 0));
            }
            // Wiederkehrende Fahrt: Zugnummer an ihrem Bahnhof und in ihrer Richtung
            $z = &$zuege[$e['station'] . '|' . $e['dir'] . '|' . $e['zug']];
            if (!is_array($z)) {
                $z = ['fahrten' => 0, 'puenktlich' => 0, 'spaet' => 0, 'ausfall' => 0, 'versp_summe' => 0, 'gefahren' => 0];
            }
            // Stammdaten vom juengsten Tag
            foreach (['zug', 'linie', 'ziel', 'ab', 'plan', 'dir'] as $feld) {
                $z[$feld] = (string) ($e[$feld] ?? '');
            }
            $z[$erg]++;
            $z['fahrten']++;
            if (empty($e['ausfall'])) {
                $z['gefahren']++;
                $z['versp_summe'] += max(0, (int) ($e['versp'] ?? 0));
            }
            unset($z);
        }
        $verlauf[] = $v;
    }
    $k['schnitt'] = $k['gefahren'] ? round($k['versp_summe'] / $k['gefahren'], 1) : 0;
    $k['quote'] = $k['fahrten'] ? $k['puenktlich'] / $k['fahrten'] : null;
    $k['tage_beobachtet'] = count(array_filter($verlauf, function ($v) { return $v['beobachtet']; }));
    unset($k['versp_summe'], $k['gefahren']);

    return ['kennzahlen' => $k, 'verlauf' => $verlauf, 'top' => zl_ranglisten($zuege)];
}

/**
 * Unzuverlaessigste und zuverlaessigste Zuege, je hoechstens ZL_TOP und ohne
 * Ueberschneidung. In die Wertung kommt nur, wer mindestens halb so oft
 * beobachtet wurde wie der am haeufigsten beobachtete Zug (wenigstens
 * NB_ZUGLOG_MIN_TAGE Fahrten), damit kein Zug mit zwei Fahrten oben steht.
 * Massstab ist der Anteil puenktlicher Fahrten, danach Ausfaelle und mittlere
 * Verspaetung.
 */
function zl_ranglisten(array $zuege)
{
    $most = max(array_column($zuege, 'fahrten') ?: [0]);
    $min = max(NB_ZUGLOG_MIN_TAGE, (int) ceil($most / 2));
    $liste = [];
    foreach ($zuege as $key => $z) {
        if ($z['fahrten'] < $min) {
            continue;
        }
        $z['quote'] = $z['puenktlich'] / $z['fahrten'];
        $z['schnitt'] = $z['gefahren'] ? round($z['versp_summe'] / $z['gefahren'], 1) : 0;
        unset($z['versp_summe'], $z['gefahren']);
        $liste[$key] = $z;
    }
    $schlecht = array_filter($liste, function ($z) { return $z['quote'] < 1; });
    uasort($schlecht, function ($a, $b) {
        return [$a['quote'], $b['ausfall'], $b['schnitt'], $a['plan']] <=> [$b['quote'], $a['ausfall'], $a['schnitt'], $b['plan']];
    });
    $schlecht = array_slice($schlecht, 0, ZL_TOP, true);
    $gut = array_diff_key($liste, $schlecht);
    uasort($gut, function ($a, $b) {
        return [$b['quote'], $a['schnitt'], $a['plan']] <=> [$a['quote'], $b['schnitt'], $b['plan']];
    });

    return ['unzuverlaessig' => array_values($schlecht), 'zuverlaessig' => array_values(array_slice($gut, 0, ZL_TOP, true)), 'min' => $min];
}

/** Dashboard fuer einen Filter, je Filter NB_ZUGSTAT_TTL Sekunden gecacht. */
function zl_dashboard(array $f, $now = null, $frisch = false)
{
    $now = $now ?? time();
    $datei = NORDBAHN_CACHE_DIR . '/zugstat-' . md5(json_encode($f)) . '.json';
    $cache = $frisch ? null : nordbahn_read_json($datei);
    if (is_array($cache) && isset($cache['stand']) && $now - (int) $cache['stand'] < NB_ZUGSTAT_TTL) {
        return $cache;
    }
    $d = ['stand' => $now] + zl_dashboard_rechnen(zl_lesen($f['tage'], $now), $f, $now);
    nordbahn_write_json($datei, $d);
    zl_aufraeumen($now);

    return $d;
}
