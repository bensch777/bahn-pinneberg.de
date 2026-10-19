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

/** Verlauf einer Stoerung als Zeitleiste. */
function nb_render_verlauf_schritte(array $schritte, $now = null)
{
    if (!$schritte) {
        return '';
    }
    $label = ['neu' => 'Störung gemeldet', 'ende' => 'Neues voraussichtliches Ende', 'text' => 'Neuer Stand', 'ki' => 'Pendler-Text aktualisiert'];
    $h = '<ol class="zeitleiste">';
    $vorText = null;
    $vorTitel = null;
    $vorEnde = null;
    foreach ($schritte as $s) {
        // Unveraenderte Texte und Titel nicht in jedem Schritt wiederholen
        $text = $s['ki_text'] !== '' ? $s['ki_text'] : $s['text'];
        if ($text === $vorText) {
            $text = '';
        } else {
            $vorText = $text;
        }
        $hinweis = $s['ende'] !== '' && $s['ende'] !== $vorEnde ? 'Gemeldetes Ende: ' . nb_fmt_wann(nb_ts($s['ende']), $now, true) : '';
        $vorEnde = $s['ende'];
        $titel = $label[$s['art']] ?? 'Aktualisiert';
        if ($s['ki_titel'] !== '' && $s['art'] !== 'neu' && $s['ki_titel'] !== $vorTitel) {
            $titel .= ': ' . $s['ki_titel'];
        }
        if ($s['ki_titel'] !== '') {
            $vorTitel = $s['ki_titel'];
        }
        $h .= '<li class="ist-' . $s['art'] . '"><time class="zeitleiste__zeit">' . nb_e(nb_fmt_wann($s['ts'], $now)) . '</time><p class="zeitleiste__titel">' . nb_e($titel) . '</p>'
            . ($text !== '' || $hinweis !== '' ? '<p class="zeitleiste__text">' . nb_e($text) . ($hinweis !== '' ? '<span class="zeitleiste__hinweis">' . nb_e($hinweis) . '</span>' : '') . '</p>' : '') . '</li>';
    }

    return $h . '</ol>';
}

/** Teilen-Zeile: WhatsApp, Telegram, X und Mail mit Titel und Seitenadresse. */
function nb_render_teilen($url, $titel)
{
    $text = rawurlencode($titel);
    $u = rawurlencode($url);
    $icons = [
        'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 0 1 12 3.8Zm-3 4.4c-.2 0-.5.1-.7.3-.3.3-1 1-1 2.3s1 2.7 1.1 2.9c.2.2 2 3.1 4.9 4.2 2.4.9 2.9.8 3.4.7.5 0 1.7-.7 1.9-1.4.2-.7.2-1.2.2-1.4-.1-.1-.3-.2-.6-.3l-2-1c-.3-.1-.5-.2-.7.2-.2.3-.8 1-.9 1.1-.2.2-.3.2-.6.1-.3-.2-1.2-.5-2.3-1.5-.9-.8-1.5-1.7-1.6-2-.2-.3 0-.5.1-.6l.4-.5.3-.5v-.5l-.9-2.2c-.2-.5-.4-.5-.6-.5H9Z"/></svg>',
        'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>',
        'x'        => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.2 2.3h3.3l-7.2 8.2 8.5 11.2h-6.6l-5.2-6.8-6 6.8H1.7l7.7-8.8L1.3 2.3H8l4.7 6.2 5.5-6.2Zm-1.2 19.4h1.8L7.1 4.1H5.2l11.8 17.6Z"/></svg>',
        'mail'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm1 2.4V17h16V7.4l-8 5.3-8-5.3ZM5.1 7l6.9 4.6L18.9 7H5.1Z"/></svg>',
    ];
    $links = [
        ['WhatsApp', 'https://wa.me/?text=' . rawurlencode($titel . ' ' . $url), $icons['whatsapp']],
        ['Telegram', 'https://t.me/share/url?url=' . $u . '&text=' . $text, $icons['telegram']],
        ['X', 'https://x.com/intent/post?text=' . $text . '&url=' . $u, $icons['x']],
        ['Mail', 'mailto:?subject=' . $text . '&body=' . rawurlencode($titel . "\n" . $url), $icons['mail']],
    ];
    $h = '<div class="teilen"><span class="teilen__label">Teilen</span>';
    foreach ($links as $l) {
        $h .= '<a class="teilen__link" href="' . nb_e($l[1]) . '"' . ($l[0] !== 'Mail' ? ' rel="noopener" target="_blank"' : '') . '>' . $l[2] . nb_e($l[0]) . '</a>';
    }

    return $h . '</div>';
}

/** Weiterlesen-Zeile aus [Text => URL]. */
function nb_render_weiter(array $links)
{
    $h = '<ul class="weiter">';
    foreach ($links as $text => $url) {
        $extern = strpos($url, 'http') === 0;
        $h .= '<li><a href="' . nb_e($url) . '"' . ($extern ? ' rel="noopener nofollow"' : '') . '>' . nb_e($text) . '</a></li>';
    }

    return $h . '</ul>';
}
