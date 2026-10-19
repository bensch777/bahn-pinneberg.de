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
function nb_link($art, $id)
{
    return nb_root() . $art . '/' . rawurlencode(nb_anker($id)) . '/';
}

/** Absoluter Link (Push-Nachrichten, lage.md, Sitemap, Canonical). */
function nb_ereignis_url($art, $id)
{
    return nordbahn_site_url() . '/' . $art . '/' . rawurlencode(nb_anker($id)) . '/';
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

/** Schritte des Verlaufs fuer die Anzeige: Pendler-Text, der nur nachgetragen wurde, zaehlt nicht als Update. */
function nb_verlauf_schritte(array $verlauf)
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
            } elseif (trim((string) ($vorher['ki_text'] ?? '')) === '') {
                // Pendler-Text nur nachgetragen: in den vorigen Schritt einarbeiten
                $out[count($out) - 1]['ki_titel'] = (string) ($v['ki_titel'] ?? '');
                $out[count($out) - 1]['ki_text'] = (string) ($v['ki_text'] ?? '');
                $vorher = $v;
                continue;
            } else {
                $art = 'ki';
            }
        }
        $out[] = ['ts' => $ts, 'art' => $art, 'ki_titel' => (string) ($v['ki_titel'] ?? ''), 'ki_text' => (string) ($v['ki_text'] ?? ''), 'text' => (string) ($v['text'] ?? ''), 'ende' => (string) ($v['ende'] ?? '')];
        $vorher = $v;
    }

    return $out;
}

/* ---------- HTML ---------- */

/** Liste betroffener Zuege (aus nb_zuege_im_zeitraum). */
function nb_render_zuege_liste(array $info)
{
    if (!$info['zuege']) {
        return '<p class="feed__empty">Keine auffälligen Züge im Zeitraum beobachtet.</p>';
    }
    $h = '<ol class="feed">';
    foreach ($info['zuege'] as $z) {
        if ($z['ausfall']) {
            $status = 'ausgefallen';
            $cls = ' zug--ausfall';
        } elseif ($z['teil'] !== '') {
            $status = $z['teil'] === 'beginnt' ? 'erst unterwegs gestartet' : 'vorzeitig geendet';
            $cls = ' zug--teil';
        } else {
            $status = '+' . $z['versp'] . ' min' . ($z['max'] > $z['versp'] ? ' (zeitweise +' . $z['max'] . ')' : '');
            $cls = ' zug--spaet';
        }
        $h .= '<li><div class="feed__time">' . nb_e(nb_fmt_uhr($z['ts'])) . '</div><div><p class="feed__title">' . nb_e(trim($z['linie'] . ' ab ' . $z['ab'] . ' nach ' . $z['ziel']))
            . ' <span class="zug__status' . $cls . '">' . nb_e($status) . '</span></p></div></li>';
    }

    return $h . '</ol>';
}

/** Verlauf einer Stoerung als Zeitleiste. */
function nb_render_verlauf_schritte(array $schritte, $now = null)
{
    if (!$schritte) {
        return '';
    }
    $label = ['neu' => 'Gemeldet', 'ende' => 'Ende geändert', 'text' => 'Neuer Stand', 'ki' => 'Pendler-Text aktualisiert'];
    $h = '<ol class="feed">';
    foreach ($schritte as $s) {
        $text = $s['ki_text'] !== '' ? $s['ki_text'] : $s['text'];
        $meta = [];
        if ($s['ende'] !== '') {
            $meta[] = 'gemeldetes Ende ' . nb_fmt_wann(nb_ts($s['ende']), $now, true);
        }
        $h .= '<li><div class="feed__time">' . nb_e(nb_fmt_wann($s['ts'], $now)) . '</div><div><p class="feed__title">' . nb_e($label[$s['art']] ?? 'Aktualisiert')
            . ($s['ki_titel'] !== '' && $s['art'] !== 'neu' ? ': ' . nb_e($s['ki_titel']) : '') . '</p>'
            . ($text !== '' || $meta ? '<p class="feed__meta">' . nb_e($text) . ($meta ? ($text !== '' ? '<br>' : '') . nb_e(implode(', ', $meta)) : '') . '</p>' : '') . '</div></li>';
    }

    return $h . '</ol>';
}
