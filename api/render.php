<?php
/**
 * Server-seitiges Rendern der Statusseite.
 *
 * dashboard.js baut dieselben Bausteine im Browser und aktualisiert sie
 * laufend. Hier entstehen sie einmal beim Seitenaufruf aus den gleichen Daten,
 * damit Suchmaschinen, Messenger-Vorschauen und Browser ohne JavaScript den
 * aktuellen Stand im HTML vorfinden. Die Logik (Schwellen, Texte) muss mit der
 * im Script uebereinstimmen.
 */

const NB_EIL_AKUT_S          = 4 * 3600;   // Eilmeldung gilt so lange als akut
const NB_BETRIEB_ALERT_ANTEIL = 0.25;      // darueber "Betrieb gestoert" statt "beeintraechtigt"
const NB_ABF_MAX_AGE_S        = 10 * 60;   // so alt darf der Abfahrten-Cache fuer die Karte sein

function nb_e($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** "13.10., 18:14 Uhr" in deutscher Zeit, wie fmtDateTime im Script. */
function nb_fmt_datetime($iso)
{
    if (!$iso) {
        return '';
    }
    try {
        $dt = new DateTime($iso);
    } catch (Exception $e) {
        return '';
    }
    $dt->setTimezone(new DateTimeZone('Europe/Berlin'));

    return $dt->format('d.m., H:i') . ' Uhr';
}

/** "vor 12 Min.", "vor 3 Std.", "gestern", wie relTime im Script. */
function nb_rel_time($ts)
{
    $diff = max(0, time() - $ts);
    $min = (int) round($diff / 60);
    if ($min < 1) {
        return 'gerade eben';
    }
    if ($min < 60) {
        return 'vor ' . $min . ' Min.';
    }
    $h = (int) round($min / 60);
    if ($h < 24) {
        return 'vor ' . $h . ' Std.';
    }
    $d = (int) round($h / 24);

    return $d === 1 ? 'gestern' : 'vor ' . $d . ' Tagen';
}

function nb_ts($iso)
{
    $ts = $iso ? strtotime($iso) : false;

    return $ts === false ? null : $ts;
}

/** "Heute", "Gestern" oder "13.10." in deutscher Zeit, bezogen auf $now. */
function nb_fmt_tag($ts, $now = null, $klein = false)
{
    $tz = new DateTimeZone('Europe/Berlin');
    $tag = (new DateTime('@' . (int) $ts))->setTimezone($tz)->format('Y-m-d');
    $heute = (new DateTime('@' . (int) ($now ?? time())))->setTimezone($tz);
    if ($tag === $heute->format('Y-m-d')) {
        return $klein ? 'heute' : 'Heute';
    }
    if ($tag === $heute->modify('-1 day')->format('Y-m-d')) {
        return $klein ? 'gestern' : 'Gestern';
    }

    return (new DateTime('@' . (int) $ts))->setTimezone($tz)->format('d.m.');
}

function nb_ist_heute($ts, $now = null)
{
    return nb_fmt_tag($ts, $now) === 'Heute';
}

/** "Heute, 08:50 Uhr" bzw. "13.10., 18:14 Uhr" (wie fmtWann im Script). */
function nb_fmt_wann($ts, $now = null, $klein = false)
{
    $uhr = (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');

    return nb_fmt_tag($ts, $now, $klein) . ', ' . $uhr . ' Uhr';
}

/** Ende einer Stoerung: am selben Tag nur die Uhrzeit, sonst mit Tag. */
function nb_fmt_bis($start, $ende, $now = null)
{
    $tz = new DateTimeZone('Europe/Berlin');
    $a = (new DateTime('@' . (int) $start))->setTimezone($tz);
    $b = (new DateTime('@' . (int) $ende))->setTimezone($tz);
    if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
        return 'bis ' . $b->format('H:i') . ' Uhr';
    }

    return 'bis ' . nb_fmt_wann($ende, $now, true);
}

/** Eilmeldungen aus Historie und Live-Liste zusammengefuehrt, aelteste zuerst (wie prepare im Script). */
function nb_items(array $data)
{
    $live = [];
    foreach ($data['eilmeldungen_live'] ?? [] as $e) {
        if (!empty($e['id'])) {
            $live[$e['id']] = $e;
        }
    }
    $items = [];
    $seen = [];
    // Der Pendler-Titel der KI (aus der Historie) ersetzt den Nordbahn-Titel in der
    // Anzeige; der Originaltitel bleibt als 'original' fuer den Tooltip erhalten.
    $push = function ($id, $title, $iso, $l, $kiTitel = '', $kiText = '') use (&$items, &$seen) {
        $ts = nb_ts($iso);
        if (!$id || $ts === null) {
            return;
        }
        $seen[$id] = true;
        $kiTitel = trim((string) $kiTitel);
        $items[] = [
            'id'       => $id,
            'title'    => $kiTitel !== '' ? $kiTitel : (string) $title,
            'original' => (string) $title,
            'text'     => trim((string) $kiText),
            'ts'       => $ts,
            'stand'    => $l && !empty($l['stand']) ? nb_ts($l['stand']) : null,
            'link'     => $l && !empty($l['link']) ? $l['link'] : '',
        ];
    };
    foreach ($data['eilmeldungen'] ?? [] as $e) {
        if ($e) {
            $push($e['id'] ?? '', $e['title'] ?? '', $e['seen_at'] ?? '', $live[$e['id'] ?? ''] ?? null, $e['ki_titel'] ?? '', $e['ki_text'] ?? '');
        }
    }
    foreach ($data['eilmeldungen_live'] ?? [] as $e) {
        if ($e && empty($seen[$e['id'] ?? ''])) {
            $push($e['id'] ?? '', $e['title'] ?? '', $e['stand'] ?? '', $e);
        }
    }
    usort($items, function ($a, $b) {
        return $a['ts'] <=> $b['ts'];
    });

    return $items;
}

function nb_plural($n, $one, $many)
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/** Karte 1: Infrastruktur laut strecken-info.de. */
function nb_status_infra(array $data)
{
    $hasData = !empty($data['received_at']);
    $list = $hasData ? ($data['stoerungen'] ?? []) : [];
    $ok = $hasData && !empty($data['quellen']['strecken_info_ok']);
    $schwer = 0;
    foreach ($list as $st) {
        if (!preg_match('/^leicht/i', $st['einschraenkung'] ?? '')) {
            $schwer++;
        }
    }
    if (!$hasData) {
        return ['level' => 'unknown', 'title' => 'Noch keine Daten', 'detail' => ''];
    }
    if (!$list) {
        return $ok
            ? ['level' => 'ok', 'title' => 'Keine Infrastrukturstörung', 'detail' => '']
            : ['level' => 'unknown', 'title' => 'Infrastrukturstörungen unbekannt', 'detail' => ''];
    }
    if ($schwer === 0) {
        return ['level' => 'warn', 'title' => nb_plural(count($list), 'leichte Infrastrukturstörung', 'leichte Infrastrukturstörungen'), 'detail' => ''];
    }

    return ['level' => 'alert', 'title' => nb_plural(count($list), 'Infrastrukturstörung', 'Infrastrukturstörungen'), 'detail' => ''];
}

/** Karte 2: Eilmeldungen der Nordbahn, akut heisst juenger als vier Stunden. */
function nb_status_eil(array $data, array $items)
{
    if (empty($data['received_at'])) {
        return ['level' => 'unknown', 'title' => 'Noch keine Daten', 'detail' => ''];
    }
    if (!$items) {
        return ['level' => 'ok', 'title' => 'Keine akuten Eilmeldungen', 'detail' => ''];
    }
    $last = $items[count($items) - 1];
    $ts = $last['stand'] ?? $last['ts'];
    $age = time() - $ts;
    if ($age > NB_EIL_AKUT_S) {
        return ['level' => 'ok', 'title' => 'Keine akuten Eilmeldungen', 'detail' => 'Letzte ' . nb_rel_time($ts)];
    }

    return ['level' => $age < 3600 ? 'alert' : 'warn', 'title' => 'Eilmeldung ' . nb_rel_time($ts), 'detail' => $last['title']];
}

/** Letzter Stand der Abfahrten fuer den Bahnhof, wenn er juenger als zehn Minuten ist. */
function nb_read_abfahrten($heimat)
{
    $abf = nordbahn_read_json(NORDBAHN_CACHE_DIR . '/abfahrten-' . $heimat . '.json');
    if (!is_array($abf) || empty($abf['ok']) || empty($abf['fetched_at'])) {
        return null;
    }
    $ts = nb_ts($abf['fetched_at']);
    if ($ts === null || time() - $ts > NB_ABF_MAX_AGE_S) {
        return null;
    }

    return $abf;
}

/** Karte 3: Fahrplanlage nach dem Anteil betroffener Zuege (wie renderBetrieb im Script). */
function nb_status_betrieb($abf)
{
    if (!$abf || empty($abf['richtungen'])) {
        return ['level' => 'unknown', 'title' => 'Lade Fahrplan …', 'detail' => ''];
    }
    $total = 0;
    $ausfaelle = 0;
    $umleitungen = 0;
    $verspaetet = 0;
    $maxDelay = 0;
    $teil = 0;
    $teilOrt = '';
    foreach (['hin', 'rueck'] as $key) {
        foreach (array_slice($abf['richtungen'][$key] ?? [], 0, 6) as $d) {
            if (!empty($d['ersatz_fuer'])) {
                continue;
            }
            $total++;
            $rb60 = preg_match('/RB\s?60/i', $d['linie'] ?? '');
            $altona = !$rb60 && (stripos($d['ab'] ?? '', 'altona') !== false || stripos($d['ziel'] ?? '', 'altona') !== false || !empty($d['ersatz']));
            if (!empty($d['teil'])) {
                $teil++;
                $teilOrt = $d['teil']['name'] ?? '';
            } elseif (!empty($d['ausfall']) && empty($d['ersatz'])) {
                $ausfaelle++;
            } elseif ($altona) {
                $umleitungen++;
            } elseif (($d['verspaetung'] ?? 0) >= 10) {
                $verspaetet++;
                $maxDelay = max($maxDelay, (int) $d['verspaetung']);
            }
        }
    }
    $betroffen = $ausfaelle + $umleitungen + $verspaetet + $teil;
    if (!$total || !$betroffen) {
        return ['level' => 'ok', 'title' => 'Fahrplan läuft', 'detail' => ''];
    }
    $parts = [];
    if ($ausfaelle) {
        $parts[] = nb_plural($ausfaelle, 'Zugausfall', 'Zugausfälle');
    }
    if ($teil) {
        $parts[] = nb_plural($teil, 'Zug', 'Züge') . ' nur ab/bis ' . $teilOrt . (stripos($teilOrt, 'pinneberg') !== false ? ', dazwischen S-Bahn' : '');
    }
    if ($umleitungen) {
        $parts[] = $umleitungen === 1 ? 'Umleitung über Altona' : $umleitungen . ' Umleitungen über Altona';
    }
    if ($verspaetet) {
        $parts[] = 'Verspätungen bis ' . $maxDelay . ' Min';
    }
    $anteil = $betroffen / $total;
    $level = $anteil > NB_BETRIEB_ALERT_ANTEIL ? 'alert' : 'warn';

    return [
        'level'  => $level,
        'title'  => $level === 'alert' ? 'Betrieb gestört' : 'Betrieb beeinträchtigt',
        'detail' => $betroffen . ' von ' . $total . ' Zügen (' . round($anteil * 100) . ' %): ' . implode(', ', $parts),
    ];
}

/** Gesamtlage fuer das Vorschaubild: alert > warn > ok, sonst info. */
function nb_overall_level(array $cards)
{
    $levels = array_column($cards, 'level');
    if (in_array('alert', $levels, true)) {
        return 'alert';
    }
    if (in_array('warn', $levels, true)) {
        return 'warn';
    }
    if ($cards['infra']['level'] === 'ok') {
        return 'ok';
    }

    return 'info';
}

function nb_render_status_card($id, array $st)
{
    $icons = ['ok' => '✓', 'warn' => '!', 'alert' => '!', 'unknown' => '?'];
    $html = '<div class="card status status--' . nb_e($st['level']) . '" id="' . nb_e($id) . '" data-ssr>';
    $html .= '<div class="status__icon" aria-hidden="true">' . ($icons[$st['level']] ?? '?') . '</div>';
    $html .= '<h3 class="status__title">' . nb_e($st['title']);
    if ($st['detail'] !== '') {
        $html .= '<small>' . nb_e($st['detail']) . '</small>';
    }
    $html .= '</h3></div>';

    return $html;
}

function nb_render_incident(array $s)
{
    $rows = [];
    $abschnitte = [];
    foreach ($s['abschnitte'] ?? [] as $a) {
        $teil = implode(' – ', array_filter([$a['von'] ?? '', $a['bis'] ?? '']));
        if (!empty($a['strecke'])) {
            $teil .= ' (Strecke ' . $a['strecke'] . ')';
        }
        if ($teil !== '') {
            $abschnitte[] = $teil;
        }
    }
    $rows['Abschnitt'] = $abschnitte ? implode(', ', $abschnitte) : implode(', ', $s['betriebsstellen'] ?? []);
    $rows['Auswirkung'] = implode(', ', $s['wirkungen'] ?? []);
    $rows['Einschränkung'] = !empty($s['einschraenkung']) ? mb_strtoupper(mb_substr($s['einschraenkung'], 0, 1)) . mb_substr($s['einschraenkung'], 1) : '';
    $zeit = '';
    if (!empty($s['beginn'])) {
        $zeit = 'seit ' . nb_fmt_datetime($s['beginn']);
    }
    if (!empty($s['ende'])) {
        // "abgelaufen": das gemeldete Ende ist vorbei, die Stoerung dauert aber an
        $zeit .= ($zeit ? ', ' : '') . (!empty($s['abgelaufen']) ? 'gemeldetes Ende ' . nb_fmt_datetime($s['ende']) . ' überschritten, dauert an' : 'voraussichtlich bis ' . nb_fmt_datetime($s['ende']));
    } elseif (!empty($s['abgelaufen'])) {
        $zeit .= ($zeit ? ', ' : '') . 'dauert länger als gemeldet';
    }
    $rows['Zeitraum'] = $zeit;

    // Liegt der Pendler-Text der KI vor, reichen Titel, Text und Zeitraum; die technischen
    // Details (Originalmeldung, Abschnitt, Wirkung, Einschraenkung) zeigt die Karte nur, solange
    // der Text noch fehlt.
    $ki = !empty($s['ki_titel']) || !empty($s['ki_text']);
    $titel = $ki && !empty($s['ki_titel']) ? $s['ki_titel'] : ($s['titel'] ?? 'Störung');
    $text = $ki && !empty($s['ki_text']) ? $s['ki_text'] : ($s['text'] ?? '');
    if ($ki) {
        $rows = ['Zeitraum' => $rows['Zeitraum']];
    }

    $html = '<li class="card incident"><h4 class="incident__title">' . nb_e($titel) . '</h4>';
    if ($text !== '') {
        $html .= '<p class="incident__text">' . nb_e($text) . '</p>';
    }
    $html .= '<dl class="incident__meta">';
    foreach ($rows as $label => $value) {
        if ($value === '') {
            continue;
        }
        $html .= '<div><dt>' . nb_e($label) . '</dt><dd>' . nb_e($value) . '</dd></div>';
    }
    $html .= '</dl></li>';

    return $html;
}

/** Die letzten Eilmeldungen als Listeneintraege, neueste zuerst (wie renderFeed im Script). */
/**
 * Kennzahlen aus dem Stoerungs-Logbuch fuer die Statistik-Seite. Gezaehlt wird
 * nach Beginn (first_seen); die Dauer reicht bis zum letzten Zeitpunkt, zu dem
 * die Stoerung noch aktiv gemeldet war (last_seen), bei laufenden also bis jetzt.
 */
function nb_statistik(array $verlauf, $now = null)
{
    $now = $now ?? time();
    $eintraege = [];
    $seit = null;
    foreach ($verlauf as $e) {
        if (!is_array($e) || empty($e['key'])) {
            continue;
        }
        $start = nb_ts($e['first_seen'] ?? '');
        $ende = nb_ts($e['last_seen'] ?? '');
        if ($start === null || $ende === null) {
            continue;
        }
        $ende = max($start, $ende);
        $titel = trim((string) ($e['titel'] ?? 'Störung'));
        $ursache = trim(explode(' – ', $titel)[0]) ?: 'Störung';
        $eintraege[] = [
            'key'      => $e['key'],
            'titel'    => trim((string) (($e['ki_titel'] ?? '') !== '' ? $e['ki_titel'] : $titel)),
            'ursache'  => $ursache,
            'start'    => $start,
            'ende'     => $ende,
            'dauer'    => $ende - $start,
        ];
        $seit = $seit === null ? $start : min($seit, $start);
    }

    $tage30 = array_values(array_filter($eintraege, function ($e) use ($now) {
        return $e['start'] >= $now - 30 * 86400;
    }));
    $dauer30 = array_sum(array_column($tage30, 'dauer'));
    $laengste = null;
    foreach ($tage30 as $e) {
        if ($laengste === null || $e['dauer'] > $laengste['dauer']) {
            $laengste = $e;
        }
    }

    // Letzte acht Kalenderwochen (ISO), aelteste zuerst, die laufende Woche zuletzt
    $wochen = [];
    $montag = strtotime('monday this week 00:00', $now);
    for ($i = 7; $i >= 0; $i--) {
        $von = strtotime('-' . $i . ' weeks', $montag);
        $bis = $von + 7 * 86400;
        $n = 0;
        $dauer = 0;
        foreach ($eintraege as $e) {
            if ($e['start'] >= $von && $e['start'] < $bis) {
                $n++;
                $dauer += $e['dauer'];
            }
        }
        $wochen[] = ['kw' => (int) date('W', $von), 'von' => $von, 'bis' => $bis - 1, 'n' => $n, 'dauer' => $dauer];
    }

    // Haeufigste Ursachen der letzten 90 Tage
    $ursachen = [];
    foreach ($eintraege as $e) {
        if ($e['start'] < $now - 90 * 86400) {
            continue;
        }
        $u = $e['ursache'];
        if (!isset($ursachen[$u])) {
            $ursachen[$u] = ['name' => $u, 'n' => 0, 'dauer' => 0];
        }
        $ursachen[$u]['n']++;
        $ursachen[$u]['dauer'] += $e['dauer'];
    }
    usort($ursachen, function ($a, $b) {
        return [$b['n'], $b['dauer']] <=> [$a['n'], $a['dauer']];
    });

    return [
        'gesamt'   => count($eintraege),
        'seit'     => $seit,
        'tage30'   => ['n' => count($tage30), 'dauer' => $dauer30, 'schnitt' => $tage30 ? (int) round($dauer30 / count($tage30)) : 0, 'laengste' => $laengste],
        'wochen'   => $wochen,
        'ursachen' => array_slice(array_values($ursachen), 0, 6),
    ];
}

/**
 * Aufgehobene Stoerungen aus dem Logbuch, juengste zuerst. Aktive (noch in
 * stoerungen gemeldete) bleiben draussen, die stehen oben in den Karten.
 * Titel und Text nehmen den Pendler-Text der KI, wenn es ihn gibt.
 */
function nb_verlauf_items(array $data)
{
    $aktiv = [];
    foreach ($data['stoerungen'] ?? [] as $s) {
        if (!empty($s['key'])) {
            $aktiv[$s['key']] = true;
        }
    }
    $out = [];
    foreach ($data['stoerungen_verlauf'] ?? [] as $e) {
        if (!is_array($e) || empty($e['key']) || isset($aktiv[$e['key']])) {
            continue;
        }
        $start = nb_ts($e['first_seen'] ?? '');
        $ende = nb_ts($e['last_seen'] ?? '');
        if ($start === null || $ende === null) {
            continue;
        }
        $out[] = [
            'key'   => $e['key'],
            'titel' => trim((string) (($e['ki_titel'] ?? '') !== '' ? $e['ki_titel'] : ($e['titel'] ?? 'Störung'))),
            'text'  => trim((string) ($e['ki_text'] ?? '')),
            'start' => $start,
            'ende'  => max($start, $ende),
        ];
    }
    usort($out, function ($a, $b) {
        return $b['ende'] <=> $a['ende'];
    });

    return $out;
}

/** Balkenbreite in 5-Prozent-Stufen (0..100) fuer data-w, CSP-konform ohne Inline-Style. */
function nb_bar_stufe($wert, $max)
{
    if ($max <= 0 || $wert <= 0) {
        return 0;
    }

    return (int) max(5, min(100, 5 * round(20 * $wert / $max)));
}

/** "35 Min." oder "2 Std. 10 Min." */
function nb_dauer($sekunden)
{
    $min = (int) round($sekunden / 60);
    if ($min < 60) {
        return max(1, $min) . ' Min.';
    }
    $h = intdiv($min, 60);
    $rest = $min % 60;

    return $h . ' Std.' . ($rest ? ' ' . $rest . ' Min.' : '');
}

/** Liste der aufgehobenen Stoerungen fuer die Startseite (wie renderVerlauf im Script). */
function nb_render_verlauf(array $items, $limit, $maxAgeDays, $now = null)
{
    $now = $now ?? time();
    $cutoff = $maxAgeDays ? $now - $maxAgeDays * 86400 : 0;
    $list = array_slice(array_values(array_filter($items, function ($it) use ($cutoff) {
        return $it['ende'] >= $cutoff;
    })), 0, $limit);
    if (!$list) {
        return '<li><p class="feed__empty">' . ($maxAgeDays ? 'Keine aufgehobene Infrastrukturstörung in den letzten ' . $maxAgeDays . ' Tagen.' : 'Noch keine Infrastrukturstörung erfasst.') . '</p></li>';
    }
    $html = '';
    foreach ($list as $it) {
        $html .= '<li' . (nb_ist_heute($it['ende'], $now) ? ' class="is-heute"' : '') . '>'
            . '<div class="feed__time">' . nb_e(nb_fmt_wann($it['start'], $now)) . '</div><div>'
            . '<p class="feed__title">' . nb_e($it['titel']) . '</p>'
            . '<p class="feed__meta">' . nb_e(nb_fmt_bis($it['start'], $it['ende'], $now)) . ' · ' . nb_e(nb_dauer($it['ende'] - $it['start']))
            . ($it['text'] !== '' ? '<br>' . nb_e($it['text']) : '') . '</p></div></li>';
    }

    return $html;
}

function nb_render_feed(array $items, $limit, $maxAgeDays, $now = null)
{
    $now = $now ?? time();
    $cutoff = $maxAgeDays ? $now - $maxAgeDays * 86400 : 0;
    $list = array_values(array_filter($items, function ($it) use ($cutoff) {
        return ($it['stand'] ?? $it['ts']) >= $cutoff;
    }));
    $list = array_reverse(array_slice($list, -$limit));
    if (!$list) {
        return '<li><p class="feed__empty">' . ($maxAgeDays ? 'Keine Eilmeldung in den letzten ' . $maxAgeDays . ' Tagen.' : 'Noch keine Eilmeldungen erfasst.') . '</p></li>';
    }
    $html = '';
    foreach ($list as $it) {
        $ts = $it['stand'] ?? $it['ts'];
        $title = $it['title'] !== '' ? $it['title'] : $it['id'];
        $tooltip = !empty($it['original']) && $it['original'] !== $it['title'] ? ' title="Nordbahn: ' . nb_e($it['original']) . '"' : '';
        $html .= '<li' . (nb_ist_heute($ts, $now) ? ' class="is-heute"' : '') . '>'
            . '<div class="feed__time">' . nb_e(nb_fmt_wann($ts, $now)) . '</div><div><p class="feed__title"' . $tooltip . '>';
        $html .= $it['link']
            ? '<a href="' . nb_e($it['link']) . '" rel="noopener nofollow">' . nb_e($title) . '</a>'
            : nb_e($title);
        $html .= '</p>';
        if (($it['text'] ?? '') !== '') {
            $html .= '<p class="feed__meta">' . nb_e($it['text']) . '</p>';
        }
        $html .= '</div></li>';
    }

    return $html;
}
