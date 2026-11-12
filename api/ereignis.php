<?php
/**
 * Ereignisseiten: eine Seite je Infrastrukturstoerung (/stoerung/<key>/) und je
 * Eilmeldung (/eilmeldung/<id>/). Hier liegen die Datenhelfer: Ereignis aus Log
 * und Lagebild finden, betroffene Zuege aus dem Zuglog im Zeitraum, Eilmeldungen
 * und Stoerungen im selben Zeitraum, Links auf die Seiten.
 * Erwartet store.php, render.php und zuglog.php.
 */

/** Relative Wurzel fuer Links in Listen ("./", "../", "../../"); Seiten setzen sie. */
function nb_root($neu = null)
{
    static $root = './';
    if ($neu !== null) {
        $root = $neu;
    }

    return $root;
}

/** Relativer Link auf eine Ereignisseite: stoerung/<key>/ oder eilmeldung/<id>/. */
/**
 * Lesbarer Zusatz fuer die Adresse einer Ereignisseite: Kleinbuchstaben, Umlaute
 * umschrieben, alles andere wird Bindestrich, hoechstens 60 Zeichen an einer
 * Wortgrenze. Gleiche Regel wie slug() im Script und in den n8n-Workflows.
 */
function nb_slug($text)
{
    $s = mb_strtolower(trim((string) $text), 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    if (strlen($s) > 60) {
        $s = substr($s, 0, 60);
        $s = strpos($s, '-') !== false ? substr($s, 0, strrpos($s, '-')) : $s;
    }

    return $s;
}

/** Pfad einer Ereignisseite: /<art>/<id>/<slug>/, der Slug ist optional. */
function nb_ereignis_pfad($art, $id, $titel = '')
{
    $slug = nb_slug($titel);

    return $art . '/' . rawurlencode(nb_anker($id)) . '/' . ($slug !== '' ? $slug . '/' : '');
}

/**
 * Fehlt der Slug in der aufgerufenen Adresse oder ist er veraltet (der Titel kann
 * sich aendern), geht es per 301 auf die kanonische Adresse. Alte Links aus
 * Telegram und X bleiben so gueltig.
 */
function nb_slug_pruefen($kanonisch, $titel)
{
    $ist = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
    if ($ist !== nb_slug($titel) && PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Location: ' . $kanonisch, true, 301);
        exit;
    }
}

function nb_link($art, $id, $titel = '')
{
    return nb_root() . nb_ereignis_pfad($art, $id, $titel);
}

/** Absoluter Link (Push-Nachrichten, lage.md, Sitemap, Canonical). */
function nb_ereignis_url($art, $id, $titel = '')
{
    return nordbahn_site_url() . '/' . nb_ereignis_pfad($art, $id, $titel);
}

/** Klarname eines Bahnhofskuerzels, Hamburger Bahnhoefe eingeschlossen. */
function nb_bahnhof_name($id)
{
    $hamburg = ['AH' => 'Hamburg Hbf', 'AA' => 'Hamburg-Altona', 'ADF' => 'Hamburg Dammtor'];

    return NORDBAHN_BAHNHOEFE[$id]['name'] ?? $hamburg[$id] ?? (string) $id;
}

/**
 * Eine Stoerung aus dem Logbuch, angereichert mit dem Live-Stand, wenn sie noch
 * aktiv ist. Null, wenn der Key unbekannt ist.
 */
function nb_stoerung_finden(array $data, $key)
{
    $log = null;
    foreach ($data['stoerungen_verlauf'] ?? [] as $e) {
        if (is_array($e) && ($e['key'] ?? '') === $key) {
            $log = $e;
            break;
        }
    }
    $aktiv = null;
    foreach ($data['stoerungen'] ?? [] as $s) {
        if (is_array($s) && ($s['key'] ?? '') === $key) {
            $aktiv = $s;
            break;
        }
    }
    if ($log === null && $aktiv === null) {
        return null;
    }
    $q = $aktiv ?? $log;
    $start = nb_ts($log['first_seen'] ?? ($aktiv['beginn'] ?? '')) ?? time();
    $ende = $log !== null ? (nb_ts($log['last_seen'] ?? '') ?? $start) : time();
    $kiTitel = trim((string) ($q['ki_titel'] ?? ''));
    $kiText = trim((string) ($q['ki_text'] ?? ''));

    return [
        'key'             => $key,
        'titel'           => $kiTitel !== '' ? $kiTitel : trim((string) ($q['titel'] ?? 'Störung')),
        'titel_technisch' => trim((string) ($q['titel'] ?? '')),
        'ki_text'         => $kiText,
        'text'            => trim((string) ($q['text'] ?? '')),
        'abschnitte'      => $q['abschnitte'] ?? [],
        'betriebsstellen' => $q['betriebsstellen'] ?? [],
        'wirkungen'       => $q['wirkungen'] ?? [],
        'einschraenkung'  => (string) ($q['einschraenkung'] ?? ''),
        'ki_folgen'       => (string) ($q['ki_folgen'] ?? ''),
        'beginn_gemeldet' => (string) ($q['beginn'] ?? ''),
        'ende_gemeldet'   => (string) ($q['ende'] ?? ''),
        'abgelaufen'      => $aktiv !== null && !empty($aktiv['abgelaufen']),
        'aktiv'           => $aktiv !== null,
        'start'           => $start,
        'ende'            => $aktiv !== null ? time() : max($start, $ende),
        'verlauf'         => is_array($log['verlauf'] ?? null) ? $log['verlauf'] : [],
    ];
}

/** Eine Eilmeldung aus Historie und Live-Liste (Felder wie nb_items), oder null. */
function nb_eilmeldung_finden(array $data, $id)
{
    foreach (nb_items($data) as $it) {
        if ($it['id'] === $id) {
            return $it;
        }
    }

    return null;
}

/**
 * Betroffene Zuege im Zeitraum aus dem Zuglog: Ausfaelle, Teilausfaelle und
 * Verspaetungen ab NB_ZUG_SPAET Minuten, deren Planzeit zwischen $start und $ende
 * liegt (mit $rand Sekunden Spielraum). Hoechstens sieben Tage.
 */
function nb_zuege_im_zeitraum($start, $ende, $rand = 600, $limit = 40)
{
    $ende = max($start, $ende);
    $tz = new DateTimeZone('Europe/Berlin');
    $tag = (new DateTime('@' . $start))->setTimezone($tz)->setTime(0, 0);
    $letzter = (new DateTime('@' . $ende))->setTimezone($tz)->setTime(0, 0);
    $zuege = [];
    for ($i = 0; $i < 7 && $tag <= $letzter; $i++, $tag->modify('+1 day')) {
        $log = nordbahn_read_json(NB_ZUGLOG_DIR . '/' . $tag->format('Y-m-d') . '.json');
        foreach (is_array($log) ? $log : [] as $e) {
            if (!is_array($e) || empty($e['zug']) || !preg_match('/^\d{2}:\d{2}$/', (string) ($e['plan'] ?? ''))) {
                continue;
            }
            $versp = (int) ($e['versp'] ?? 0);
            $ausfall = !empty($e['ausfall']);
            $teil = (string) ($e['teil'] ?? '');
            if (!$ausfall && $teil === '' && $versp < NB_ZUG_SPAET) {
                continue;
            }
            $ts = (new DateTime($tag->format('Y-m-d') . ' ' . $e['plan'], $tz))->getTimestamp();
            if ($ts < $start - $rand || $ts > $ende + $rand) {
                continue;
            }
            $zuege[] = [
                'ts'      => $ts,
                'zug'     => (string) $e['zug'],
                'linie'   => (string) ($e['linie'] ?? ''),
                'ziel'    => (string) ($e['ziel'] ?? ''),
                'ab'      => nb_bahnhof_name((string) ($e['station'] ?? '')),
                'dir'     => (string) ($e['dir'] ?? ''),
                'versp'   => $versp,
                'max'     => (int) ($e['max'] ?? $versp),
                'ausfall' => $ausfall,
                'teil'    => $teil,
            ];
        }
    }
    usort($zuege, function ($a, $b) {
        return [$a['ts'], $a['ab']] <=> [$b['ts'], $b['ab']];
    });
    $zaehl = ['ausfaelle' => 0, 'teil' => 0, 'spaet' => 0];
    foreach ($zuege as $z) {
        if ($z['ausfall']) {
            $zaehl['ausfaelle']++;
        } elseif ($z['teil'] !== '') {
            $zaehl['teil']++;
        } else {
            $zaehl['spaet']++;
        }
    }

    return ['zuege' => array_slice($zuege, 0, $limit), 'gesamt' => count($zuege)] + $zaehl;
}

/** Eilmeldungen (nb_items), deren Stand im Zeitraum liegt, juengste zuerst. */
function nb_eilmeldungen_im_zeitraum(array $items, $start, $ende, $rand = 3600)
{
    $out = array_values(array_filter($items, function ($it) use ($start, $ende, $rand) {
        $ts = $it['stand'] ?? $it['ts'];

        return $ts >= $start - $rand && $ts <= max($start, $ende) + $rand;
    }));

    return array_reverse($out);
}

/** Andere Stoerungen (aktive und aufgehobene), die sich mit dem Zeitraum ueberschneiden. */
function nb_stoerungen_im_zeitraum(array $data, $start, $ende, $ausser = '')
{
    $out = [];
    foreach (nb_verlauf_items($data, true) as $it) {
        if ($it['key'] === $ausser) {
            continue;
        }
        $itEnde = !empty($it['aktiv']) ? time() : $it['ende'];
        if ($it['start'] <= max($start, $ende) && $itEnde >= $start) {
            $out[] = $it;
        }
    }

    return $out;
}

/**
 * Schritte des Verlaufs fuer die Anzeige: neu, neues Ende, neuer Text. Aendert sich
 * nur der Pendler-Text der KI, ist das kein eigener Schritt: Er kommt bis zu fuenf
 * Minuten nach der Aenderung bei strecken-info an und ist nur deren lesbare Fassung,
 * also wandert er in den vorigen Schritt.
 */
function nb_verlauf_schritte(array $verlauf, $aufgehoben = null)
{
    $out = [];
    $vorher = null;
    foreach ($verlauf as $v) {
        if (!is_array($v) || empty($v['t'])) {
            continue;
        }
        $ts = nb_ts($v['t']);
        if ($ts === null) {
            continue;
        }
        $art = 'neu';
        if ($vorher !== null) {
            if (($v['ende'] ?? '') !== ($vorher['ende'] ?? '')) {
                $art = 'ende';
            } elseif (trim((string) ($v['text'] ?? '')) !== trim((string) ($vorher['text'] ?? ''))) {
                $art = 'text';
            } else {
                // Nur der Pendler-Text ist neu: in den vorigen Schritt einarbeiten
                $out[count($out) - 1]['ki_titel'] = (string) ($v['ki_titel'] ?? '');
                $out[count($out) - 1]['ki_text'] = (string) ($v['ki_text'] ?? '');
                $vorher = $v;
                continue;
            }
        }
        $out[] = ['ts' => $ts, 'art' => $art, 'ki_titel' => (string) ($v['ki_titel'] ?? ''), 'ki_text' => (string) ($v['ki_text'] ?? ''), 'text' => (string) ($v['text'] ?? ''), 'ende' => (string) ($v['ende'] ?? '')];
        $vorher = $v;
    }
    // Abschluss der Geschichte: die Aufhebung als letzter Schritt
    if ($aufgehoben !== null && $out) {
        $out[] = ['ts' => (int) $aufgehoben, 'art' => 'aufgehoben', 'ki_titel' => '', 'ki_text' => '', 'text' => '', 'ende' => '', 'dauer' => nb_dauer(max(60, (int) $aufgehoben - $out[0]['ts']))];
    }

    return $out;
}

/* ---------- HTML ---------- */

/** Betroffene Zuege (aus nb_zuege_im_zeitraum) als Tabelle mit Status-Pille. */
function nb_render_zuege_liste(array $info)
{
    if (!$info['zuege']) {
        return '<p class="feed__empty">Keine auffälligen Züge im Zeitraum beobachtet.</p>';
    }
    $h = '<table class="zuege">';
    foreach ($info['zuege'] as $z) {
        $klein = '';
        if ($z['ausfall']) {
            $status = 'Ausgefallen';
            $cls = 'ausfall';
        } elseif ($z['teil'] !== '') {
            $status = $z['teil'] === 'beginnt' ? 'Erst unterwegs gestartet' : 'Vorzeitig geendet';
            $cls = 'teil';
        } else {
            $status = '+' . $z['versp'] . ' min';
            $cls = 'spaet';
            if ($z['max'] > $z['versp']) {
                $klein = 'zeitweise +' . $z['max'];
            }
        }
        $h .= '<tr><td class="zuege__zeit">' . nb_e(nb_fmt_uhr($z['ts'])) . '</td>'
            . '<td><span class="zuege__zug">' . nb_e($z['linie']) . '</span> <span class="zuege__weg">' . nb_e(trim('ab ' . $z['ab'] . ' nach ' . $z['ziel'])) . '</span></td>'
            . '<td class="zuege__status"><span class="pill pill--' . $cls . '">' . nb_e($status) . '</span>' . ($klein !== '' ? '<small>' . nb_e($klein) . '</small>' : '') . '</td></tr>';
    }

    return $h . '</table>';
}

/**
 * Verlauf einer Stoerung als Aenderungsliste: je Schritt ein Etikett (Gemeldet, Update,
 * Verlängert, Verkürzt, Aufgehoben), die Zeit und nur das, was sich geaendert hat: der
 * neue Text, ein verschobenes Ende als "Ende: 22:51 Uhr → morgen 14:51 Uhr", zum Schluss
 * die Dauer. Immer alle Schritte, nichts eingeklappt.
 * $opts: kopf_text = Text, der schon oben auf der Seite steht (wird im Schritt nicht
 * wiederholt, nur als "Aktueller Text, siehe oben" vermerkt); ohne_dauer = die Dauer steht
 * schon im Zeitraum, der Schritt Aufgehoben bleibt ohne.
 */
function nb_render_verlauf_schritte(array $schritte, $now = null, array $opts = [])
{
    $kopfText = trim((string) ($opts['kopf_text'] ?? ''));
    $ohneDauer = !empty($opts['ohne_dauer']);
    if (!$schritte) {
        return '';
    }
    $now = $now ?? time();
    $items = [];
    $vorText = null;
    $vorEnde = '';
    foreach ($schritte as $s) {
        $text = $s['ki_text'] !== '' ? $s['ki_text'] : $s['text'];
        if ($text === $vorText || $s['art'] === 'aufgehoben') {
            $text = '';
        } else {
            $vorText = $text;
        }
        $etikett = ['neu' => 'Gemeldet', 'text' => 'Update', 'ende' => 'Ende verschoben', 'aufgehoben' => 'Aufgehoben'][$s['art']] ?? 'Update';
        $hinweis = '';
        $oben = $text !== '' && $kopfText !== '' && trim($text) === $kopfText;
        if ($oben) {
            $text = '';
            // Beim ersten Schritt reicht das Weglassen; spaeter zeigt der Vermerk, dass hier der Text kam
            $oben = $s['art'] !== 'neu';
        }
        if ($s['art'] === 'ende' && $s['ende'] !== '') {
            $neu = nb_ts($s['ende']);
            $alt = nb_ts($vorEnde);
            if ($neu !== null && $alt !== null) {
                $etikett = $neu > $alt ? 'Verlängert' : 'Verkürzt';
                // Am selben Tag den Tag nicht wiederholen: "gestern 19:03 Uhr → 20:03 Uhr"
                $neuText = nb_fmt_tag_rel($alt, $now) === nb_fmt_tag_rel($neu, $now) ? nb_fmt_uhr($neu) : nb_fmt_zeit_rel($neu, $now);
                $hinweis = 'Ende: ' . nb_fmt_zeit_rel($alt, $now) . ' → ' . $neuText;
            } elseif ($neu !== null) {
                $hinweis = 'Ende: ' . nb_fmt_zeit_rel($neu, $now);
            }
        } elseif ($s['art'] === 'neu' && $s['ende'] !== '' && nb_ts($s['ende']) !== null) {
            $hinweis = 'Voraussichtlich bis ' . nb_fmt_zeit_rel(nb_ts($s['ende']), $now);
        } elseif ($s['art'] === 'aufgehoben' && !$ohneDauer) {
            $hinweis = 'Dauer ' . ($s['dauer'] ?? '');
        }
        if ($oben) {
            $hinweis = trim($hinweis . ($hinweis !== '' ? ' · ' : '') . 'Aktueller Text, siehe oben');
        }
        if ($s['ende'] !== '') {
            $vorEnde = $s['ende'];
        }
        $items[] = '<li class="ist-' . $s['art'] . '"><p class="zeitleiste__kopf"><span class="zeitleiste__etikett">' . nb_e($etikett) . '</span><time class="zeitleiste__zeit" datetime="' . date('c', $s['ts']) . '">' . nb_e(nb_fmt_wann($s['ts'], $now)) . '</time></p>'
            . ($text !== '' ? '<p class="zeitleiste__text">' . nb_e($text) . '</p>' : '')
            . ($hinweis !== '' ? '<p class="zeitleiste__hinweis">' . nb_e($hinweis) . '</p>' : '')
            . '</li>';
    }
    $h = '';
    $h .= '<ol class="zeitleiste">' . implode('', $items);

    return $h . '</ol>';
}

