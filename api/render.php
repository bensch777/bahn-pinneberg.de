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
const NB_EIL_HINWEIS_S       = 12 * 3600;  // bis dahin nennt die gruene Karte "Letzte vor …"
const NB_STOERUNG_NACHLAUF_S  = 3600;      // aufgehobene Stoerung bleibt so lange unter "Aktuelle Meldungen"
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
    $push = function ($id, $title, $iso, $l, $kiTitel = '', $kiText = '', $wichtig = null) use (&$items, &$seen) {
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
            'wichtig'  => $wichtig !== null ? (bool) $wichtig : nb_wichtig_eilmeldung($kiTitel, $kiText, $title),
        ];
    };
    foreach ($data['eilmeldungen'] ?? [] as $e) {
        if ($e) {
            $push($e['id'] ?? '', $e['title'] ?? '', $e['seen_at'] ?? '', $live[$e['id'] ?? ''] ?? null, $e['ki_titel'] ?? '', $e['ki_text'] ?? '', $e['wichtig'] ?? null);
        }
    }
    foreach ($data['eilmeldungen_live'] ?? [] as $e) {
        if ($e && empty($seen[$e['id'] ?? ''])) {
            $push($e['id'] ?? '', $e['title'] ?? '', $e['stand'] ?? '', $e, '', '', $e['wichtig'] ?? null);
        }
    }
    // Nach der angezeigten Zeit: Stand der Nordbahn, sonst Erfassung; aelteste zuerst
    usort($items, function ($a, $b) {
        return ($a['stand'] ?? $a['ts']) <=> ($b['stand'] ?? $b['ts']);
    });

    return $items;
}

function nb_plural($n, $one, $many)
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/** Karte 1: Infrastruktur laut strecken-info.de. */
/* ---------- Hinweise ohne spuerbare Folgen ----------
 * strecken-info fuehrt manche Meldung tagelang als aktiv, obwohl fuer Pendler
 * nichts mehr passiert ("Betrieb wieder planmaessig, Ersatzteil wird eingebaut").
 * Solche Meldungen sind Hinweise, keine Stoerungen: Lagekarte bleibt gruen, keine
 * Karte auf der Startseite, nicht "wichtig", kein Push, nicht in der Statistik.
 * Sichtbar bleiben sie unter /aktuell/ und auf ihrer Ereignisseite. */

/**
 * Hinweis ohne spuerbare Folgen? Massgeblich ist das Urteil der KI aus n8n
 * (ki_folgen = keine; jeder andere Wert heisst Stoerung). Fehlt das Urteil, gilt
 * eine Meldung nur dann als Hinweis, wenn strecken-info fuer den Nahverkehr gar
 * keine Wirkung nennt (wirkungen vorhanden, aber leer) und die Einschraenkung
 * nicht "schwer" ist. Im Zweifel Stoerung: ohne Angaben bleibt es eine.
 */
function nb_stoerung_hinweis(array $s)
{
    $folgen = (string) ($s['ki_folgen'] ?? '');
    if ($folgen !== '') {
        return $folgen === 'keine';
    }

    return isset($s['wirkungen']) && is_array($s['wirkungen']) && !$s['wirkungen']
        && mb_strtolower(trim((string) ($s['einschraenkung'] ?? ''))) !== 'schwer';
}

/** Aktive Stoerungen in echte Stoerungen und Hinweise aufteilen, Reihenfolge bleibt. */
function nb_stoerungen_teilen(array $liste)
{
    $out = ['stoerungen' => [], 'hinweise' => []];
    foreach ($liste as $s) {
        if (is_array($s)) {
            $out[nb_stoerung_hinweis($s) ? 'hinweise' : 'stoerungen'][] = $s;
        }
    }

    return $out;
}

/** Karte 1: Infrastruktur laut strecken-info.de; Hinweise ohne Folgen zaehlen nicht, die gruene Karte nennt sie klein. */
function nb_status_infra(array $data)
{
    $hasData = !empty($data['received_at']);
    $teile = nb_stoerungen_teilen($hasData ? ($data['stoerungen'] ?? []) : []);
    $list = $teile['stoerungen'];
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
        $hinweise = count($teile['hinweise']);

        return $ok || $hinweise
            ? ['level' => 'ok', 'title' => 'Keine Infrastrukturstörung', 'detail' => $hinweise ? nb_plural($hinweise, 'Hinweis ohne Folgen', 'Hinweise ohne Folgen') : '']
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
        // Die Nordbahn liefert keine Gueltigkeit; der Hinweis zeigt, ob eine juengere Meldung noch nachwirken kann
        return ['level' => 'ok', 'title' => 'Keine akuten Eilmeldungen', 'detail' => $age <= NB_EIL_HINWEIS_S ? 'Letzte ' . nb_rel_time($ts) : ''];
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

/**
 * Aktiver Sonderfahrplan der Nordbahn, nach dem RB61 und RB71 planmaessig ab/bis Altona fahren,
 * z. B. bei Bauarbeiten "26.10. - 02.11.: Alle Züge der Linien RB 61 und RB 71 fahren ab/bis
 * Hamburg-Altona; …". Gilt von 'von' bis einschliesslich 'bis' (deutsche Zeit). Wie
 * baufahrplan() im Script.
 *
 * @return array|null der Sonderfahrplan oder null
 */
function nb_baufahrplan(array $data, $now = null)
{
    $heute = (new DateTime('@' . ($now ?? time())))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    foreach ($data['sonderfahrplaene'] ?? [] as $s) {
        $titel = (string) ($s['title'] ?? '');
        if (($s['von'] ?? '') <= $heute && $heute <= ($s['bis'] ?? '')
            && preg_match('/RB\s?(61|71)/i', $titel) && preg_match('/\b(ab|bis|nach|in)\s+(hamburg-)?altona\b/iu', $titel)) {
            return $s;
        }
    }

    return null;
}

/** "Bis 02.11.: Alle Züge …" aus dem Sonderfahrplan, ohne den Zeitraum vorn im Titel. */
function nb_baufahrplan_text(array $bau)
{
    $text = preg_replace('/^[^:]*\d{1,2}\.\d{1,2}\.(\d{2,4})?\s*:\s*/u', '', (string) $bau['title']);
    $bis = DateTime::createFromFormat('!Y-m-d', (string) $bau['bis']);

    return ($bis ? 'Bis ' . $bis->format('d.m.') . ': ' : '') . $text;
}

/**
 * Karte 3: Fahrplanlage nach dem Anteil betroffener Zuege (wie renderBetrieb im Script).
 * Mit $bau (nb_baufahrplan) sind Fahrten ueber Altona planmaessig: die Karte ist dann
 * mindestens gelb und nennt den Sonderfahrplan, gezaehlt werden nur Ausfaelle und
 * Verspaetungen.
 */
function nb_status_betrieb($abf, $bau = null)
{
    if (!$abf || empty($abf['richtungen'])) {
        return ['level' => 'unknown', 'title' => 'Lade Nordbahn …', 'detail' => ''];
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
            if (!empty($d['teil']) && empty($d['teil']['plan'])) {
                $teil++;
                $teilOrt = $d['teil']['name'] ?? '';
            } elseif (!empty($d['ausfall']) && empty($d['ersatz'])) {
                $ausfaelle++;
            } elseif ($altona && !$bau) {
                $umleitungen++;
            } elseif (($d['verspaetung'] ?? 0) >= 10) {
                $verspaetet++;
                $maxDelay = max($maxDelay, (int) $d['verspaetung']);
            }
        }
    }
    $betroffen = $ausfaelle + $umleitungen + $verspaetet + $teil;
    $bauText = $bau ? nb_baufahrplan_text($bau) : '';
    if (!$total || !$betroffen) {
        return $bau
            ? ['level' => 'warn', 'title' => 'Nordbahn fährt nach Baufahrplan', 'detail' => $bauText]
            : ['level' => 'ok', 'title' => 'Nordbahn fährt planmäßig', 'detail' => ''];
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
        'title'  => $level === 'alert' ? 'Nordbahn gestört' : ($bau ? 'Nordbahn fährt nach Baufahrplan' : 'Nordbahn beeinträchtigt'),
        'detail' => ($bau ? $bauText . ' · ' : '') . $betroffen . ' von ' . $total . ' Zügen (' . round($anteil * 100) . ' %): ' . implode(', ', $parts),
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

/** Eine Lagekarte; mit $href ein Link (Infrastruktur und Eilmeldungen fuehren zu /aktuell/). */
function nb_render_status_card($id, array $st, $versteckt = false, $href = '')
{
    $icons = ['ok' => '✓', 'warn' => '!', 'alert' => '!', 'unknown' => '?'];
    $tag = $href !== '' ? 'a' : 'div';
    $html = '<' . $tag . ' class="card status status--' . nb_e($st['level']) . '" id="' . nb_e($id) . '"' . ($href !== '' ? ' href="' . nb_e($href) . '"' : '') . ' data-ssr' . ($versteckt ? ' hidden' : '') . '>';
    $html .= '<div class="status__icon" aria-hidden="true">' . ($icons[$st['level']] ?? '?') . '</div>';
    $html .= '<h3 class="status__title">' . nb_e($st['title']);
    if ($st['detail'] !== '') {
        $html .= '<small>' . nb_e($st['detail']) . '</small>';
    }
    $html .= '</h3></' . $tag . '>';

    return $html;
}

/* ---------- Lagekarten kompakt ----------
 * Sind alle Karten gruen, steht nur eine Karte "Alles planmaessig". Sonst
 * erscheinen nur die nicht gruenen Karten, die gruenen fasst eine Zeile darunter
 * zusammen. Unbekannte Karten ("Lade …") zaehlen nicht als gruen. */

const NB_STATUS_NAMEN = ['infra' => 'Infrastruktur', 'eil' => 'Eilmeldungen', 'betrieb' => 'Nordbahn', 's3' => 'S3'];

/** "A", "A und B", "A, B und C" (wie aufzaehlung im Script). */
function nb_aufzaehlung(array $teile)
{
    $letzter = array_pop($teile);

    return $teile ? implode(', ', $teile) . ' und ' . $letzter : (string) $letzter;
}

/** Zusammenfassung der Lagekarten (wie renderKompakt im Script): alles (bool), detail, rest (Text der Sammelzeile), versteckt je Karte. */
function nb_status_kompakt(array $cards)
{
    $gruen = [];
    foreach ($cards as $key => $st) {
        if ($st['level'] === 'ok') {
            $gruen[] = NB_STATUS_NAMEN[$key] ?? $key;
        }
    }
    $alles = count($gruen) === count($cards);
    $detail = 'Keine Störung oder Eilmeldung, Nordbahn' . (isset($cards['s3']) ? ' und S3' : '') . ' planmäßig';
    if (($cards['eil']['detail'] ?? '') !== '') {
        $detail .= ' · ' . str_replace('Letzte ', 'letzte Eilmeldung ', $cards['eil']['detail']);
    }
    $versteckt = [];
    foreach ($cards as $key => $st) {
        $versteckt[$key] = $alles || $st['level'] === 'ok';
    }

    return ['alles' => $alles, 'detail' => $detail, 'rest' => !$alles && $gruen ? nb_aufzaehlung($gruen) . ' ok' : '', 'versteckt' => $versteckt];
}

/**
 * Karte einer laufenden Infrastrukturstoerung. $ausblendbar: mit Knopf zum
 * Wegklicken (Startseite), bis zum Start des Scripts versteckt, siehe nb_weg_knopf().
 */
function nb_render_incident(array $s, $ausblendbar = false)
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

    $weg = $ausblendbar && !empty($s['key']);
    $html = '<li class="card incident"' . ($weg ? ' data-weg-id="' . nb_e('st:' . $s['key']) . '"' : '') . '><h4 class="incident__title">' . nb_e($titel) . '</h4>';
    $link = !empty($s['key']) && function_exists('nb_link') ? nb_link('stoerung', $s['key'], $titel) : '';
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
    $html .= '</dl>';
    if ($link !== '') {
        $html .= '<p class="incident__link"><a href="' . nb_e($link) . '">Details und Verlauf</a></p>';
    }
    if ($weg) {
        $html .= nb_weg_knopf('incident__weg', 'Störung');
    }
    $html .= '</li>';

    return $html;
}

/**
 * Knopf zum Wegklicken einer Meldung auf der Startseite. Der Eintrag traegt die
 * Kennung in data-weg-id (eil:<id>, st:<key>, hvv:<id>), das Script merkt sich
 * weggeklickte Kennungen im Browser (localStorage) und versteckt die Eintraege
 * beim naechsten Besuch wieder. Bis zum Start des Scripts bleibt der Knopf
 * versteckt, ohne JavaScript taete er nichts.
 */
function nb_weg_knopf($klasse, $art)
{
    return '<button type="button" class="' . $klasse . '" aria-label="' . nb_e($art . ' ausblenden') . '" title="Diese Meldung f&uuml;r mich ausblenden" hidden>&times;</button>';
}

/** Die letzten Eilmeldungen als Listeneintraege, neueste zuerst (wie renderFeed im Script). */
/**
 * Kennzahlen aus dem Stoerungs-Logbuch fuer die Statistik-Seite, ueber die
 * letzten $tage Tage. Gezaehlt wird nach Beginn (first_seen); die Dauer reicht bis zum letzten Zeitpunkt, zu dem
 * die Stoerung noch aktiv gemeldet war (last_seen), bei laufenden also bis jetzt.
 */
function nb_statistik(array $verlauf, $now = null, $tage = 30)
{
    $now = $now ?? time();
    $eintraege = [];
    $seit = null;
    $hinweise = 0;
    foreach ($verlauf as $e) {
        if (!is_array($e) || empty($e['key'])) {
            continue;
        }
        if (nb_stoerung_hinweis($e)) {
            $hinweise++;
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

    $imZeitraum = array_values(array_filter($eintraege, function ($e) use ($now, $tage) {
        return $e['start'] >= $now - $tage * 86400;
    }));
    $dauerZeitraum = array_sum(array_column($imZeitraum, 'dauer'));
    $laengste = null;
    foreach ($imZeitraum as $e) {
        if ($laengste === null || $e['dauer'] > $laengste['dauer']) {
            $laengste = $e;
        }
    }

    // Kalenderwochen (ISO), die den Zeitraum abdecken, aelteste zuerst, die laufende Woche zuletzt
    $wochen = [];
    $montag = strtotime('monday this week 00:00', $now);
    for ($i = (int) ceil($tage / 7); $i >= 0; $i--) {
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

    // Haeufigste Ursachen im Zeitraum
    $ursachen = [];
    foreach ($eintraege as $e) {
        if ($e['start'] < $now - $tage * 86400) {
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
        'zeitraum' => ['n' => count($imZeitraum), 'dauer' => $dauerZeitraum, 'schnitt' => $imZeitraum ? (int) round($dauerZeitraum / count($imZeitraum)) : 0, 'laengste' => $laengste],
        'wochen'   => $wochen,
        'ursachen' => array_slice(array_values($ursachen), 0, 6),
    ];
}

/* ---------- Statistik-Dashboard (statistik/) ---------- */

/** Adresse der Statistikseite mit Filter; Vorgaben fallen weg, damit die Adressen kurz bleiben. */
function nb_statistik_url(array $f, array $extra = [])
{
    $q = array_merge([
        'ansicht'  => $f['ansicht'] ?? 'zuege',
        'bhf'      => $f['bhf'],
        'richtung' => $f['richtung'],
        'tage'     => $f['tage'],
        'zeit'     => $f['zeit'],
        'werktage' => $f['werktage'] ? '1' : '',
        'sort'     => $f['sort'] ?? 'zeit',
    ], $extra);
    $vorgabe = ['ansicht' => 'zuege', 'bhf' => NORDBAHN_HEIMAT_DEFAULT, 'richtung' => 'beide', 'tage' => 30, 'zeit' => 'alle', 'werktage' => '', 'sort' => 'zeit'];
    foreach ($vorgabe as $k => $v) {
        if ((string) $q[$k] === (string) $v) {
            unset($q[$k]);
        }
    }

    return './' . ($q ? '?' . http_build_query($q) : '');
}

/** "87 %" bzw. "–" ohne Werte. */
function nb_prozent($anteil)
{
    return $anteil === null ? '–' : (int) round($anteil * 100) . ' %';
}

/** Kurzer Tag fuer Achse und Tooltip: "Mo 20.10." */
function nb_tag_kurz($tag)
{
    $ts = strtotime($tag . ' 12:00:00');

    return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) date('w', $ts)] . ' ' . date('d.m.', $ts);
}

/**
 * Verlauf je Tag als gestapelte Saeulen (unten ausgefallen, darueber verspaetet,
 * oben puenktlich), Hoehe relativ zum Tag mit den meisten Fahrten. HTML statt SVG,
 * damit die Saeulen schmal bleiben; Hoehen in 5-Prozent-Stufen (CSP ohne Inline-Style).
 */
function nb_render_zug_verlauf(array $verlauf)
{
    $max = 1;
    foreach ($verlauf as $v) {
        $max = max($max, $v['puenktlich'] + $v['spaet'] + $v['ausfall']);
    }
    $html = '<div class="verlauf" role="img" aria-label="Fahrten je Tag: pünktlich, verspätet und ausgefallen. Die Zahlen stehen auch in der Tabelle darunter.">';
    foreach ($verlauf as $v) {
        $n = $v['puenktlich'] + $v['spaet'] + $v['ausfall'];
        if (!$n) {
            $html .= '<div class="verlauf__tag verlauf__tag--leer" title="' . nb_e(nb_tag_kurz($v['tag']) . ': ' . ($v['beobachtet'] ? 'keine passende Fahrt' : 'nicht beobachtet')) . '"></div>';
            continue;
        }
        $titel = nb_tag_kurz($v['tag']) . ': ' . $v['puenktlich'] . ' pünktlich, ' . $v['spaet'] . ' verspätet, ' . $v['ausfall'] . ' ausgefallen';
        $html .= '<div class="verlauf__tag" data-h="' . nb_bar_stufe($n, $max) . '" title="' . nb_e($titel) . '">';
        foreach (['ausfall', 'spaet', 'puenktlich'] as $art) {
            if ($v[$art]) {
                $html .= '<span class="verlauf__seg verlauf__seg--' . $art . '" data-h="' . nb_bar_stufe($v[$art], $n) . '"></span>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    if ($verlauf) {
        $html .= '<div class="verlauf__achse"><span>' . nb_e(nb_tag_kurz($verlauf[0]['tag'])) . '</span><span>' . nb_e(nb_tag_kurz(end($verlauf)['tag'])) . '</span></div>';
    }

    return $html;
}

/** Dieselben Zahlen als Tabelle, juengster Tag zuerst, nur Tage mit Fahrten. */
function nb_render_zug_verlauf_tabelle(array $verlauf)
{
    $h = '<table class="bars"><tr><th scope="col">Tag</th><th scope="col" class="num">P&uuml;nktlich</th><th scope="col" class="num">Versp&auml;tet</th><th scope="col" class="num">Ausgefallen</th></tr>';
    foreach (array_reverse($verlauf) as $v) {
        if ($v['puenktlich'] + $v['spaet'] + $v['ausfall'] === 0) {
            continue;
        }
        $h .= '<tr><th scope="row">' . nb_e(nb_tag_kurz($v['tag'])) . '</th><td class="num">' . (int) $v['puenktlich'] . '</td><td class="num">' . (int) $v['spaet'] . '</td><td class="num">' . (int) $v['ausfall'] . '</td></tr>';
    }

    return $h . '</table>';
}

/** Zuverlaessigkeit je Zug fuer eine Richtung; $sortLinks: [zeit-URL, ausfall-URL, aktiv]. */
function nb_render_zug_tabelle(array $zuege, $mitAb, array $sortLinks)
{
    if (!$zuege) {
        return '<p class="feed__empty">Keine Fahrt zu diesem Filter beobachtet.</p>';
    }
    [$urlZeit, $urlAusfall, $sort] = $sortLinks;
    if ($sort === 'ausfall') {
        uasort($zuege, function ($a, $b) {
            return [$b['ausfallquote'], $b['spaetquote'], $a['plan']] <=> [$a['ausfallquote'], $a['spaetquote'], $b['plan']];
        });
    }
    $kopf = function ($label, $url, $aktiv) {
        return $aktiv ? $label . ' <span aria-hidden="true">&darr;</span>' : '<a href="' . nb_e($url) . '">' . $label . '</a>';
    };
    $h = '<table class="bars bars--zuege"><tr><th scope="col">' . $kopf('Zug', $urlZeit, $sort !== 'ausfall') . '</th>' . ($mitAb ? '<th scope="col">ab</th>' : '')
        . '<th scope="col" class="num">Tage</th><th scope="col" class="num">' . $kopf('Ausf&auml;lle', $urlAusfall, $sort === 'ausfall') . '</th><th scope="col" class="bar"><span class="visually-hidden">Ausfallquote</span></th>'
        . '<th scope="col" class="num">&ge; 5 min</th><th scope="col" class="num">&Oslash; Versp.</th><th scope="col" class="num col-max">max.</th></tr>';
    foreach ($zuege as $z) {
        $wenig = $z['tage'] < NB_ZUGLOG_MIN_TAGE;
        $h .= '<tr' . ($wenig ? ' class="is-wenig"' : '') . '><th scope="row">' . nb_e($z['plan']) . ' ' . nb_e($z['linie']) . ' <span class="zug__ziel">' . nb_e($z['ziel']) . '</span></th>'
            . ($mitAb ? '<td>' . nb_e($z['ab']) . '</td>' : '')
            . '<td class="num">' . (int) $z['tage'] . '</td>'
            . '<td class="num">' . (int) $z['ausfaelle'] . ($z['teil'] ? ' <span class="zug__teil" title="Teilausf&auml;lle">+' . (int) $z['teil'] . '</span>' : '') . '</td>'
            . '<td class="bar"><div class="bar__track" aria-hidden="true"><div class="bar__fill bar__fill--rot' . ($z['ausfaelle'] ? '' : ' bar__fill--leer') . '" data-w="' . nb_bar_stufe($z['ausfaelle'], max(1, $z['tage'])) . '"></div></div></td>'
            . '<td class="num">' . (int) $z['spaet'] . '</td>'
            . '<td class="num">' . ($z['schnitt'] >= 0.05 ? '+' . nb_e(number_format($z['schnitt'], 1, ',', '')) : '0') . '</td>'
            . '<td class="num col-max">' . ($z['max'] ? '+' . (int) $z['max'] : '0') . '</td></tr>';
    }

    return $h . '</table>';
}

/**
 * Aufgehobene Stoerungen aus dem Logbuch, juengste zuerst. Aktive (noch in
 * stoerungen gemeldete) bleiben draussen, die stehen oben in den Karten.
 * Titel und Text nehmen den Pendler-Text der KI, wenn es ihn gibt.
 */
function nb_verlauf_items(array $data, $mitAktiven = false)
{
    $aktiv = [];
    foreach ($data['stoerungen'] ?? [] as $s) {
        if (!empty($s['key'])) {
            $aktiv[$s['key']] = true;
        }
    }
    $out = [];
    foreach ($data['stoerungen_verlauf'] ?? [] as $e) {
        if (!is_array($e) || empty($e['key']) || (!$mitAktiven && isset($aktiv[$e['key']])) || nb_stoerung_hinweis($e)) {
            continue;
        }
        $start = nb_ts($e['first_seen'] ?? '');
        $ende = nb_ts($e['last_seen'] ?? '');
        if ($start === null || $ende === null) {
            continue;
        }
        $laeuft = isset($aktiv[$e['key']]);
        $out[] = [
            'key'     => $e['key'],
            'titel'   => trim((string) (($e['ki_titel'] ?? '') !== '' ? $e['ki_titel'] : ($e['titel'] ?? 'Störung'))),
            'text'    => trim((string) ($e['ki_text'] ?? '')),
            'start'   => $start,
            'ende'    => max($start, $ende),
            'aktiv'   => $laeuft,
            'wichtig' => nb_wichtig_stoerung($e, $start, $laeuft ? time() : max($start, $ende)),
        ];
    }
    // Juengster Beginn zuerst, wie im Archiv
    usort($out, function ($a, $b) {
        return $b['start'] <=> $a['start'];
    });

    return $out;
}

/* ---------- Wichtige Meldungen ----------
 * Die Rubrik "Wichtig" filtert aus Stoerungen und Eilmeldungen das heraus, was
 * viele Pendler betrifft. Ein explizites Feld 'wichtig' (z. B. aus n8n) gewinnt,
 * sonst gelten die Regeln unten. Die FAQ beschreibt sie in Alltagssprache. */

/** Stoerung: schwere Einschraenkung, Ausfall/Umleitung/Ersatzverkehr/Sperrung als Wirkung oder ab zwei Stunden Dauer. */
function nb_wichtig_stoerung(array $s, $start, $ende)
{
    if (nb_stoerung_hinweis($s)) {
        return false;
    }
    if (isset($s['wichtig'])) {
        return (bool) $s['wichtig'];
    }
    if (mb_strtolower(trim((string) ($s['einschraenkung'] ?? ''))) === 'schwer') {
        return true;
    }
    foreach ((array) ($s['wirkungen'] ?? []) as $w) {
        if (preg_match('/ausfall|umleitung|ersatzverkehr|sperr|zurückgehalten/iu', (string) $w)) {
            return true;
        }
    }

    return $ende - $start >= 2 * 3600;
}

/** Eilmeldung: Ersatzverkehr, Sperrung, Streik, Bauarbeiten, Unwetter, Infrastruktur oder mehrere Zuege bzw. ein Zeitraum; einzelne kurzfristige Zugausfaelle nicht. */
function nb_wichtig_eilmeldung($titel, $text, $original = '')
{
    $t = mb_strtolower(trim($titel . ' ' . $original . ' ' . $text), 'UTF-8');

    return (bool) preg_match('/ersatzverkehr|schienenersatz|\bsev\b|sperr|streik|sonderfahrplan|bauarbeiten|baustell|unwetter|sturm|hochwasser|stellwerk|signalst|oberleitung|weichenst|polizei|feuerwehr|notarzt|personen im gleis|unfall|brand|evakuier|mehrere z|alle z|keine z|sämtliche|ganztägig|den ganzen tag|bis \d{1,2}([:.]\d{2})? uhr|bis (ca\. )?\d{1,2} uhr/u', $t);
}

/** Setzt 'wichtig' an allen Meldungen im Datensatz, damit das Script dieselbe Einstufung bekommt. */
function nb_wichtig_markieren(array $data, $now = null)
{
    $now = $now ?? time();
    foreach (['stoerungen', 'stoerungen_verlauf'] as $liste) {
        foreach ($data[$liste] ?? [] as $i => $s) {
            if (!is_array($s)) {
                continue;
            }
            $start = nb_ts($s['first_seen'] ?? ($s['beginn'] ?? '')) ?? $now;
            $ende = $liste === 'stoerungen' ? $now : (nb_ts($s['last_seen'] ?? '') ?? $start);
            $data[$liste][$i]['wichtig'] = nb_wichtig_stoerung($s, $start, max($start, $ende));
        }
    }
    foreach (['eilmeldungen', 'eilmeldungen_live'] as $liste) {
        foreach ($data[$liste] ?? [] as $i => $e) {
            if (is_array($e) && !isset($e['wichtig'])) {
                $data[$liste][$i]['wichtig'] = nb_wichtig_eilmeldung($e['ki_titel'] ?? '', $e['ki_text'] ?? '', $e['title'] ?? '');
            }
        }
    }

    return $data;
}

/**
 * Wichtige Stoerungen (auch laufende) und Eilmeldungen in einer Liste, juengste
 * zuerst. Felder: art (stoerung|eilmeldung), ts, titel, text, link, dazu key/start/
 * ende/aktiv bei Stoerungen und id bei Eilmeldungen.
 */
function nb_wichtig_items(array $data, $now = null)
{
    $now = $now ?? time();
    $out = [];
    foreach (nb_verlauf_items($data, true) as $it) {
        if ($it['wichtig']) {
            $out[] = ['art' => 'stoerung', 'ts' => $it['start'], 'titel' => $it['titel'], 'text' => $it['text'], 'link' => nb_link('stoerung', $it['key'], $it['titel']),
                'key' => $it['key'], 'start' => $it['start'], 'ende' => $it['aktiv'] ? $now : $it['ende'], 'aktiv' => $it['aktiv']];
        }
    }
    foreach (nb_items($data) as $it) {
        if ($it['wichtig']) {
            $titel = $it['title'] !== '' ? $it['title'] : $it['id'];
            $out[] = ['art' => 'eilmeldung', 'ts' => $it['stand'] ?? $it['ts'], 'titel' => $titel, 'text' => $it['text'], 'link' => nb_link('eilmeldung', $it['id'], $titel), 'id' => $it['id']];
        }
    }
    usort($out, function ($a, $b) {
        return $b['ts'] <=> $a['ts'];
    });

    return $out;
}

/**
 * Ein Eintrag der Wichtig-Listen (Rubrik, Titel, Text, bei Stoerungen Ende und Dauer).
 * $ausblendbar: mit Knopf zum Wegklicken (nur "Aktuelle Meldungen" auf der
 * Startseite), siehe nb_weg_knopf().
 */
function nb_render_wichtig_eintrag(array $it, $now, $mitTag = true, $ausblendbar = false)
{
    $zeit = $mitTag ? nb_fmt_wann($it['ts'], $now) : nb_fmt_uhr($it['ts']);
    $rubrik = $it['art'] === 'stoerung' ? 'St&ouml;rung' : 'Eilmeldung';
    $meta = [];
    if ($it['art'] === 'stoerung') {
        $meta[] = $it['aktiv'] ? 'dauert an, bisher ' . nb_dauer($it['ende'] - $it['start']) : nb_fmt_bis($it['start'], $it['ende'], $now) . ' · ' . nb_dauer($it['ende'] - $it['start']);
    }

    $wegId = $it['art'] === 'stoerung' ? (($it['key'] ?? '') !== '' ? 'st:' . $it['key'] : '') : (($it['id'] ?? '') !== '' ? 'eil:' . $it['id'] : '');
    $weg = $ausblendbar && $wegId !== '';

    return '<li' . (nb_ist_heute($it['ts'], $now) ? ' class="is-heute"' : '') . ($weg ? ' data-weg-id="' . nb_e($wegId) . '"' : '') . '><div class="feed__time">' . nb_e($zeit) . '</div><div>'
        . '<p class="feed__kicker feed__kicker--' . $it['art'] . '">' . $rubrik . ($it['art'] === 'stoerung' && $it['aktiv'] ? ' <span class="dep__ab dep__ab--aktiv">aktiv</span>' : '') . '</p>'
        . '<p class="feed__title"><a href="' . nb_e($it['link']) . '">' . nb_e($it['titel']) . '</a></p>'
        . ($meta || $it['text'] !== '' ? '<p class="feed__meta">' . nb_e(implode('', $meta)) . ($meta && $it['text'] !== '' ? '<br>' : '') . nb_e($it['text']) . '</p>' : '')
        . '</div>'
        . ($weg ? nb_weg_knopf('feed__weg', $it['art'] === 'stoerung' ? 'Störung' : 'Eilmeldung') : '')
        . '</li>';
}

/** Seite /news/: ein Abschnitt je Tag. */
function nb_render_news(array $items, $now = null)
{
    $now = $now ?? time();
    if (!$items) {
        return '<section class="section"><ol class="feed card"><li><p class="feed__empty">Noch keine wichtige Meldung erfasst.</p></li></ol></section>';
    }
    $html = '';
    foreach (nb_nach_tag($items, function ($it) { return $it['ts']; }) as $tag) {
        $html .= '<section class="section"><h2 class="section__title">' . nb_e(nb_fmt_tag_lang($tag['ts'], $now)) . '</h2><ol class="feed card">';
        foreach ($tag['items'] as $it) {
            $html .= nb_render_wichtig_eintrag($it, $now, false);
        }
        $html .= '</ol></section>';
    }

    return $html;
}

/* ---------- S-Bahn S3 und HVV-Meldungen (api/geofox.php) ---------- */

/** Letzter Geofox-Stand aus dem Cache, null wenn fehlend, unbrauchbar oder aelter als NB_ABF_MAX_AGE_S. */
function nb_read_geofox($stadt = GF_STADT_DEFAULT)
{
    $gf = nordbahn_read_json(gf_cache_file($stadt));
    if (!is_array($gf) || empty($gf['ok']) || empty($gf['fetched_at']) || !is_array($gf['s3'] ?? null)) {
        return null;
    }
    $ts = nb_ts($gf['fetched_at']);
    if ($ts === null || time() - $ts > NB_ABF_MAX_AGE_S) {
        return null;
    }

    return $gf;
}

/** Karte 4: S3 zwischen Pinneberg und Hamburg; die Regel rechnet gf_status, hier nur die Anzeige. */
function nb_status_s3($gf)
{
    if ($gf === null) {
        return ['level' => 'unknown', 'title' => 'Lade S3 …', 'detail' => ''];
    }
    if (empty($gf['ok']) || !is_array($gf['s3'] ?? null)) {
        return ['level' => 'unknown', 'title' => 'S3-Lage unbekannt', 'detail' => ''];
    }

    return ['level' => $gf['s3']['level'] ?? 'unknown', 'title' => (string) ($gf['s3']['title'] ?? 'S3'), 'detail' => (string) ($gf['s3']['detail'] ?? '')];
}

/** S3-Abfahrten ab Pinneberg als Zeilen der Abfahrtstafel (wie renderS3 im Script). */
function nb_render_s3_abfahrten(array $liste, $ab = 'Pinneberg')
{
    $html = '';
    $next = false;
    foreach ($liste as $d) {
        if (($d['ab'] ?? '') !== $ab) {
            continue;
        }
        $late = empty($d['ausfall']) && ($d['delay_min'] ?? 0) >= 3;
        $cls = 'dep' . (!empty($d['ausfall']) ? ' dep--cancelled' : ($late ? ' dep--late' : ''));
        if (empty($d['ausfall']) && !$next) {
            $cls .= ' dep--next';
            $next = true;
        }
        $zeit = nb_ts($d['zeit'] ?? '');
        $html .= '<li class="' . $cls . '"><span class="dep__dot"></span><div class="dep__time">' . nb_e($zeit ? nb_fmt_uhr($zeit) : '--:--')
            . (!empty($d['ausfall']) ? '<small>fällt aus</small>' : ($late ? '<small>+' . (int) $d['delay_min'] . ' → ' . nb_e(nb_fmt_uhr(nb_ts($d['ist']) ?? $zeit)) . '</small>' : '')) . '</div>'
            . '<div class="dep__line">' . nb_e($d['linie'] ?? 'S3') . '</div>'
            . '<div class="dep__dest"><span class="dep__ziel">' . nb_e($d['ziel'] ?? '') . '</span></div>'
            . '<div class="dep__platform">' . (($d['gleis'] ?? '') !== '' ? 'Gl. ' . nb_e($d['gleis']) : '') . '</div></li>';
    }
    if ($html === '') {
        return '<li><p class="board__empty">Keine S3-Abfahrt in den nächsten 60 Minuten gemeldet.</p></li>';
    }

    return $html;
}

/** Gueltigkeit einer HVV-Meldung als Text: "bis Fr., 17.10., 04:00 Uhr" bzw. "ab …". */
function nb_meldung_zeitraum(array $m, $now)
{
    $von = nb_ts($m['von'] ?? '');
    $bis = nb_ts($m['bis'] ?? '');
    if (!empty($m['aktiv'])) {
        return $bis !== null ? nb_fmt_bis($now, $bis, $now) : 'bis auf Weiteres';
    }
    if ($von !== null && $von > $now) {
        return 'ab ' . nb_fmt_wann($von, $now, true) . ($bis !== null ? ' ' . nb_fmt_bis($von, $bis, $now) : '');
    }

    return $bis !== null ? 'war ' . nb_fmt_bis($von ?? $bis, $bis, $now) : '';
}

/** Eine aktuelle HVV-Meldung als Listeneintrag (wie hvvEintrag im Script): Rubrik, Linien, Zeitraum, Text. */
/** Eine aktuelle HVV-Meldung als Listeneintrag; $ausblendbar wie bei nb_render_wichtig_eintrag(). */
function nb_render_hvv_eintrag(array $m, $now, $ausblendbar = false)
{
    $weg = $ausblendbar && ($m['id'] ?? '') !== '';
    $meta = [];
    if (!empty($m['linien'])) {
        $meta[] = implode(', ', $m['linien']);
    }
    $zeitraum = nb_meldung_zeitraum($m, $now);
    if ($zeitraum !== '') {
        $meta[] = $zeitraum;
    }
    $text = (string) ($m['text'] ?? '');
    if (mb_strlen($text) > 300) {
        $text = rtrim(mb_substr($text, 0, 300)) . ' …';
    }
    $von = nb_ts($m['von'] ?? '') ?? nb_ts($m['stand'] ?? '') ?? $now;

    return '<li class="is-heute"' . ($weg ? ' data-weg-id="' . nb_e('hvv:' . $m['id']) . '"' : '') . '><div class="feed__time">' . nb_e(nb_fmt_wann($von, $now)) . '</div><div>'
        . '<p class="feed__kicker feed__kicker--hvv">HVV-Meldung <span class="dep__ab dep__ab--aktiv">aktuell</span></p>'
        . '<p class="feed__title">' . (($m['link'] ?? '') !== '' ? '<a href="' . nb_e($m['link']) . '" rel="noopener nofollow">' . nb_e($m['titel'] ?? '') . '</a>' : nb_e($m['titel'] ?? '')) . '</p>'
        . ($meta || $text !== '' ? '<p class="feed__meta">' . nb_e(implode(' · ', $meta)) . ($meta && $text !== '' ? '<br>' : '') . nb_e($text) . '</p>' : '')
        . '</div>' . ($weg ? nb_weg_knopf('feed__weg', 'HVV-Meldung') : '') . '</li>';
}

/* ---------- Aktuelle Meldungen (Startseite) ----------
 * Ein Bereich statt getrennter Listen: nur was gerade gilt. Laufende Stoerungen
 * stehen als Karten unter den Lagekarten und fehlen hier; der Rueckblick liegt
 * auf /news/, /eilmeldungen/ und /stoerungen/. */

/**
 * Aktuelle Meldungen aus allen Quellen, juengste zuerst: Eilmeldungen der letzten
 * NB_EIL_AKUT_S (wie die Lagekarte), Stoerungen bis NB_STOERUNG_NACHLAUF_S nach der
 * Aufhebung und ungeplante HVV-Meldungen, die jetzt gelten. Geplante HVV-Meldungen
 * (Bauarbeiten, Vorankuendigungen) bleiben draussen: ihr Zeitraum ist oft der der
 * Ankuendigung, nicht der Einschraenkung. Inhaltsgleiche HVV-Meldungen (gleiche
 * Linien und gleicher Zeitraum) zaehlen einmal.
 */
function nb_aktuelle_meldungen(array $data, ?array $hvv, $now = null)
{
    $now = $now ?? time();
    $liste = [];
    foreach (nb_verlauf_items($data) as $it) {
        if ($it['ende'] >= $now - NB_STOERUNG_NACHLAUF_S) {
            $liste[] = ['art' => 'stoerung', 'ts' => $it['start'], 'titel' => $it['titel'], 'text' => $it['text'], 'link' => nb_link('stoerung', $it['key'], $it['titel']),
                'key' => $it['key'], 'start' => $it['start'], 'ende' => $it['ende'], 'aktiv' => false];
        }
    }
    foreach (nb_items($data) as $it) {
        $ts = $it['stand'] ?? $it['ts'];
        if ($ts >= $now - NB_EIL_AKUT_S) {
            $titel = $it['title'] !== '' ? $it['title'] : $it['id'];
            $liste[] = ['art' => 'eilmeldung', 'ts' => $ts, 'titel' => $titel, 'text' => $it['text'], 'link' => nb_link('eilmeldung', $it['id'], $titel), 'id' => $it['id']];
        }
    }
    $gesehen = [];
    foreach ($hvv ?? [] as $m) {
        if (!is_array($m) || empty($m['aktiv']) || !empty($m['geplant'])) {
            continue;
        }
        $schluessel = implode(',', $m['linien'] ?? []) . '|' . ($m['von'] ?? '') . '|' . ($m['bis'] ?? '');
        if (isset($gesehen[$schluessel])) {
            continue;
        }
        $gesehen[$schluessel] = true;
        $liste[] = ['art' => 'hvv', 'ts' => nb_ts($m['von'] ?? '') ?? nb_ts($m['stand'] ?? '') ?? $now, 'meldung' => $m];
    }
    usort($liste, function ($a, $b) {
        return $b['ts'] <=> $a['ts'];
    });

    return $liste;
}

/**
 * Inhalt des Bereichs "Aktuelle Meldungen" (wie renderMeldungen im Script); leer
 * heisst: Bereich ausblenden. Auf der Startseite ($ausblendbar) laesst sich jede
 * Meldung wegklicken, siehe nb_weg_knopf(); /aktuell/ zeigt immer alle.
 */
function nb_render_aktuelle_meldungen(array $liste, $now = null, $ausblendbar = true)
{
    $now = $now ?? time();
    if (!$liste) {
        return '';
    }
    $html = '<ol class="feed" id="meldungen-feed">';
    foreach ($liste as $it) {
        $html .= $it['art'] === 'hvv' ? nb_render_hvv_eintrag($it['meldung'], $now, $ausblendbar) : nb_render_wichtig_eintrag($it, $now, true, $ausblendbar);
    }

    return $html . '</ol>';
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

/** "in den letzten 24 Stunden" bzw. "in den letzten 3 Tagen" (wie zeitraumText im Script). */
function nb_zeitraum_text($maxAgeDays)
{
    return (int) $maxAgeDays === 1 ? 'in den letzten 24 Stunden' : 'in den letzten ' . (int) $maxAgeDays . ' Tagen';
}

/** Leer-Text der Stoerungsliste; nennt laufende Stoerungen, damit "keine" nicht nach "alles gut" klingt (wie verlaufLeerText im Script). */
function nb_verlauf_leer_text($maxAgeDays, $aktiv = 0)
{
    if ($aktiv > 0) {
        return ($maxAgeDays ? 'Keine Störung ' . nb_zeitraum_text($maxAgeDays) . ' aufgehoben, ' : 'Noch keine Störung aufgehoben, ')
            . ($aktiv === 1 ? 'eine läuft noch' : $aktiv . ' laufen noch') . ' (siehe oben).';
    }

    return $maxAgeDays ? 'Keine aufgehobene Infrastrukturstörung ' . nb_zeitraum_text($maxAgeDays) . '.' : 'Noch keine Infrastrukturstörung erfasst.';
}

/** Liste der aufgehobenen Stoerungen fuer die Startseite (wie renderVerlauf im Script). */
function nb_render_verlauf(array $items, $limit, $maxAgeDays, $now = null, $aktiv = 0)
{
    $now = $now ?? time();
    $cutoff = $maxAgeDays ? $now - $maxAgeDays * 86400 : 0;
    $list = array_slice(array_values(array_filter($items, function ($it) use ($cutoff) {
        return $it['ende'] >= $cutoff;
    })), 0, $limit);
    if (!$list) {
        return '<li><p class="feed__empty">' . nb_e(nb_verlauf_leer_text($maxAgeDays, $aktiv)) . '</p></li>';
    }
    $html = '';
    foreach ($list as $it) {
        $html .= '<li' . (nb_ist_heute($it['ende'], $now) ? ' class="is-heute"' : '') . '>'
            . '<div class="feed__time">' . nb_e(nb_fmt_wann($it['start'], $now)) . '</div><div>'
            . '<p class="feed__title"><a href="' . nb_e(nb_link('stoerung', $it['key'], $it['titel'])) . '">' . nb_e($it['titel']) . '</a></p>'
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
        return '<li><p class="feed__empty">' . ($maxAgeDays ? 'Keine Eilmeldung ' . nb_zeitraum_text($maxAgeDays) . '.' : 'Noch keine Eilmeldungen erfasst.') . '</p></li>';
    }
    $html = '';
    foreach ($list as $it) {
        $ts = $it['stand'] ?? $it['ts'];
        $title = $it['title'] !== '' ? $it['title'] : $it['id'];
        $tooltip = !empty($it['original']) && $it['original'] !== $it['title'] ? ' title="Nordbahn: ' . nb_e($it['original']) . '"' : '';
        $html .= '<li' . (nb_ist_heute($ts, $now) ? ' class="is-heute"' : '') . '>'
            . '<div class="feed__time">' . nb_e(nb_fmt_wann($ts, $now)) . '</div><div><p class="feed__title"' . $tooltip . '>';
        $html .= '<a href="' . nb_e(nb_link('eilmeldung', $it['id'], $title)) . '">' . nb_e($title) . '</a>';
        $html .= '</p>';
        if (($it['text'] ?? '') !== '') {
            $html .= '<p class="feed__meta">' . nb_e($it['text']) . '</p>';
        }
        $html .= '</div></li>';
    }

    return $html;
}

/* ---------- Archiv (eilmeldungen/, stoerungen/) ---------- */

/** Berliner Kalendertag als "Y-m-d", zum Gruppieren. */
function nb_tag_key($ts)
{
    return (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
}

/** Tagesueberschrift: "Heute, 16.10.2026", "Gestern, 15.10.2026", sonst "Dienstag, 14.10.2026". */
function nb_fmt_tag_lang($ts, $now = null)
{
    $d = (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $tag = nb_fmt_tag($ts, $now);
    if ($tag !== 'Heute' && $tag !== 'Gestern') {
        $wochentage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $tag = $wochentage[(int) $d->format('N') - 1];
    }

    return $tag . ', ' . $d->format('d.m.Y');
}

function nb_fmt_uhr($ts)
{
    return (new DateTime('@' . (int) $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i') . ' Uhr';
}

/** HTML-id fuer einen Archiveintrag, damit Push-Nachrichten direkt darauf verlinken koennen. */
function nb_anker($id)
{
    return preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $id);
}

/** Gruppiert Eintraege nach Berliner Kalendertag, Reihenfolge wie uebergeben. */
function nb_nach_tag(array $items, callable $zeit)
{
    $gruppen = [];
    foreach ($items as $it) {
        $ts = $zeit($it);
        $k = nb_tag_key($ts);
        if (!isset($gruppen[$k])) {
            $gruppen[$k] = ['ts' => $ts, 'items' => []];
        }
        $gruppen[$k]['items'][] = $it;
    }

    return $gruppen;
}

/** Eilmeldungen (juengste zuerst, siehe nb_archiv_eil), ein Abschnitt je Tag. */
function nb_render_archiv_eil(array $items, $now = null)
{
    $now = $now ?? time();
    if (!$items) {
        return '<section class="section"><ol class="feed card"><li><p class="feed__empty">Noch keine Eilmeldung erfasst.</p></li></ol></section>';
    }
    $zeit = function ($it) { return $it['stand'] ?? $it['ts']; };
    $html = '';
    foreach (nb_nach_tag($items, $zeit) as $tag) {
        $heute = nb_ist_heute($tag['ts'], $now);
        $html .= '<section class="section"><h2 class="section__title">' . nb_e(nb_fmt_tag_lang($tag['ts'], $now)) . '</h2><ol class="feed card">';
        foreach ($tag['items'] as $it) {
            $title = $it['title'] !== '' ? $it['title'] : $it['id'];
            $tooltip = !empty($it['original']) && $it['original'] !== $it['title'] ? ' title="Nordbahn: ' . nb_e($it['original']) . '"' : '';
            $html .= '<li id="' . nb_e(nb_anker($it['id'])) . '"' . ($heute ? ' class="is-heute"' : '') . '><div class="feed__time">' . nb_e(nb_fmt_uhr($zeit($it))) . '</div><div><p class="feed__title"' . $tooltip . '>'
                . '<a href="' . nb_e(nb_link('eilmeldung', $it['id'], $title)) . '">' . nb_e($title) . '</a></p>'
                . (($it['text'] ?? '') !== '' ? '<p class="feed__meta">' . nb_e($it['text']) . '</p>' : '') . '</div></li>';
        }
        $html .= '</ol></section>';
    }

    return $html;
}

/** Infrastrukturstoerungen (nach Beginn absteigend, siehe nb_archiv_stoerungen), ein Abschnitt je Tag. */
function nb_render_archiv_stoerungen(array $items, $now = null)
{
    $now = $now ?? time();
    if (!$items) {
        return '<section class="section"><ol class="feed card"><li><p class="feed__empty">Noch keine Infrastrukturstörung erfasst.</p></li></ol></section>';
    }
    $html = '';
    foreach (nb_nach_tag($items, function ($it) { return $it['start']; }) as $tag) {
        $heute = nb_ist_heute($tag['ts'], $now);
        $html .= '<section class="section"><h2 class="section__title">' . nb_e(nb_fmt_tag_lang($tag['ts'], $now)) . '</h2><ol class="feed card">';
        foreach ($tag['items'] as $it) {
            $meta = !empty($it['aktiv'])
                ? 'dauert an · bisher ' . nb_dauer(max($now, $it['ende']) - $it['start'])
                : nb_fmt_bis($it['start'], $it['ende'], $now) . ' · ' . nb_dauer($it['ende'] - $it['start']);
            $html .= '<li id="' . nb_e(nb_anker($it['key'])) . '"' . ($heute || !empty($it['aktiv']) ? ' class="is-heute"' : '') . '><div class="feed__time">' . nb_e(nb_fmt_uhr($it['start'])) . '</div><div>'
                . '<p class="feed__title"><a href="' . nb_e(nb_link('stoerung', $it['key'], $it['titel'])) . '">' . nb_e($it['titel']) . '</a>' . (!empty($it['aktiv']) ? ' <span class="dep__ab dep__ab--aktiv">aktiv</span>' : '') . '</p>'
                . '<p class="feed__meta">' . nb_e($meta) . ($it['text'] !== '' ? '<br>' . nb_e($it['text']) : '') . '</p></div></li>';
        }
        $html .= '</ol></section>';
    }

    return $html;
}

/** Alle Eilmeldungen fuer das Archiv, juengste zuerst (nach Stand der Nordbahn, sonst Erfassung). */
function nb_archiv_eil(array $data)
{
    $items = nb_items($data);
    usort($items, function ($a, $b) {
        return ($b['stand'] ?? $b['ts']) <=> ($a['stand'] ?? $a['ts']);
    });

    return $items;
}

/** Alle Infrastrukturstoerungen fuer das Archiv, aktive eingeschlossen, nach Beginn absteigend. */
function nb_archiv_stoerungen(array $data)
{
    $items = nb_verlauf_items($data, true);
    usort($items, function ($a, $b) {
        return $b['start'] <=> $a['start'];
    });

    return $items;
}

/**
 * Eine Seite einer langen Liste. Mit $zeit (Zeitstempel je Eintrag) endet eine Seite
 * nie mitten in einem Tag: sie fuellt sich mit ganzen Tagen, bis mindestens
 * $proSeite Eintraege beisammen sind. Ohne $zeit feste Bloecke zu $proSeite.
 */
function nb_seite(array $items, $proSeite, $seite, ?callable $zeit = null)
{
    $proSeite = max(1, (int) $proSeite);
    $bloecke = [];
    if ($zeit === null) {
        $bloecke = $items ? array_chunk($items, $proSeite) : [];
    } else {
        $block = [];
        $tag = null;
        foreach ($items as $it) {
            $k = nb_tag_key($zeit($it));
            if ($tag !== null && $k !== $tag && count($block) >= $proSeite) {
                $bloecke[] = $block;
                $block = [];
            }
            $block[] = $it;
            $tag = $k;
        }
        if ($block) {
            $bloecke[] = $block;
        }
    }
    $seiten = max(1, count($bloecke));
    $seite = min($seiten, max(1, (int) $seite));

    return [
        'items'  => $bloecke[$seite - 1] ?? [],
        'seite'  => $seite,
        'seiten' => $seiten,
        'gesamt' => count($items),
    ];
}

/** URL einer Seite: Seite 1 ohne Parameter. */
function nb_seite_url($basis, $seite)
{
    return $seite > 1 ? $basis . '?seite=' . (int) $seite : $basis;
}

/** Blaettern unter der Liste: neuere links, aeltere rechts, dazwischen die Seitenzahl. */
function nb_render_seitennav(array $s, $basis)
{
    if ($s['seiten'] <= 1) {
        return '';
    }
    $neuere = $s['seite'] > 1 ? '<a class="pager__link" href="' . nb_e(nb_seite_url($basis, $s['seite'] - 1)) . '" rel="prev">&larr; Neuere Meldungen</a>' : '<span></span>';
    $aeltere = $s['seite'] < $s['seiten'] ? '<a class="pager__link" href="' . nb_e(nb_seite_url($basis, $s['seite'] + 1)) . '" rel="next">&Auml;ltere Meldungen &rarr;</a>' : '<span></span>';

    return '<nav class="pager" aria-label="Seiten">' . $neuere . '<span class="pager__info">Seite ' . (int) $s['seite'] . ' von ' . (int) $s['seiten'] . '</span>' . $aeltere . '</nav>';
}

/** Filterleiste der vier Meldungsseiten; jede bleibt eine eigene Adresse. $aktiv: aktuell, news, eilmeldungen oder stoerungen. */
function nb_render_meldungen_tabs($root, $aktiv)
{
    $tabs = ['aktuell' => 'Aktiv', 'news' => 'Wichtig', 'eilmeldungen' => 'Eilmeldungen', 'stoerungen' => 'St&ouml;rungen'];
    $html = '<nav class="tabs" aria-label="Meldungen filtern">';
    foreach ($tabs as $pfad => $label) {
        $html .= '<a class="tabs__tab" href="' . nb_e($root . $pfad . '/') . '"' . ($pfad === $aktiv ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }

    return $html . '</nav>';
}

/**
 * Feedback: schwebender Knopf unten links und der Dialog dazu (inc/footer.php auf
 * jeder Seite, nur wenn nordbahn_feedback_webhook() gesetzt ist). feedback.js blendet
 * den Knopf ein und schickt das Formular an api/feedback; ohne Script bleibt er weg.
 */
function nb_render_feedback($root)
{
    $version = (int) @filemtime(__DIR__ . '/../feedback.js');

    return '<button type="button" class="feedback" id="feedback-open" aria-haspopup="dialog" aria-controls="feedback-dialog" hidden>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.98 3 3 6.36 3 10.5c0 2.3 1.24 4.35 3.2 5.71-.13 1.2-.6 2.32-1.36 3.25a.5.5 0 0 0 .45.82c1.9-.28 3.5-1.02 4.72-1.9.94.2 1.93.31 2.99.31 5.02 0 9-3.36 9-7.5S17.02 3 12 3z"/></svg>'
        . '<span>Feedback</span></button>'
        . '<dialog class="feedback-dialog" id="feedback-dialog" aria-labelledby="feedback-titel">'
        . '<form class="feedback-form" id="feedback-form" method="post" action="' . nb_e($root) . 'api/feedback" novalidate>'
        . '<div class="feedback-dialog__head"><h2 class="feedback-dialog__title" id="feedback-titel">Feedback</h2>'
        . '<button type="button" class="feedback-dialog__close" aria-label="Schlie&szlig;en">&times;</button></div>'
        . '<p class="feedback-dialog__intro">Fehler gefunden, etwas stimmt nicht oder eine Idee? Schreib es hier rein, es landet direkt beim Betreiber.</p>'
        . '<div class="feedback-form__hp" aria-hidden="true"><label for="feedback-website">Website</label><input type="text" id="feedback-website" name="website" tabindex="-1" autocomplete="off"></div>'
        . '<label for="feedback-art">Art</label>'
        . '<select id="feedback-art" name="art"><option value="fehler">Fehler</option><option value="idee">Idee</option><option value="sonst" selected>Sonstiges</option></select>'
        . '<label for="feedback-nachricht">Deine Nachricht</label>'
        . '<textarea id="feedback-nachricht" name="nachricht" rows="5" maxlength="2000" required placeholder="Was ist dir aufgefallen? Bei Fehlern helfen Bahnhof, Uhrzeit und Zug."></textarea>'
        . '<label for="feedback-kontakt">Kontakt (optional)</label>'
        . '<input type="text" id="feedback-kontakt" name="kontakt" maxlength="120" autocomplete="off" placeholder="E-Mail oder Telegram-Name, nur wenn du eine Antwort m&ouml;chtest">'
        . '<button type="submit" class="feedback-form__submit">Absenden</button>'
        . '<p class="feedback-form__status" role="status" aria-live="polite"></p>'
        . '<p class="feedback-form__hinweis">Deine Nachricht geht mit Seite und Browser-Kennung in einen privaten Discord-Kanal des Betreibers. Bitte keine sensiblen Daten.</p>'
        . '</form></dialog>'
        . '<script src="' . nb_e($root) . 'feedback.js?v=' . $version . '" defer></script>';
}

/**
 * Kopfzeile aller Seiten: Name, Hauptnavigation und rechts optionale Werkzeuge
 * ($tools, z. B. die Glocke der Startseite). Auf breiten Bildschirmen stehen die
 * Bereiche nebeneinander, auf dem Handy hinter dem Menue-Knopf, zusammen mit FAQ
 * und Changelog. Das Menue ist ein <details>, klappt also auch ohne JavaScript;
 * nav.js schliesst es bei Klick daneben und mit Escape.
 * $aktiv: lage, aktuell, news, eilmeldungen, stoerungen, ereignis, karte, statistik, faq, changelog oder impressum.
 */
function nb_render_kopf($root, $aktiv, $tools = '')
{
    $bereiche = [
        'lage' => ['', 'Start', ['lage']],
        'news' => ['news/', 'Meldungen', ['aktuell', 'news', 'eilmeldungen', 'stoerungen', 'ereignis']],
        'karte' => ['karte/', 'Live-Karte', ['karte']],
        'statistik' => ['statistik/', 'Statistik', ['statistik']],
    ];
    $extra = [
        'einstellungen' => ['einstellungen/', 'Einstellungen'],
        'faq' => ['faq/', 'H&auml;ufige Fragen'],
        'changelog' => ['changelog/', 'Was ist neu'],
    ];
    $link = function ($class, $pfad, $label, $an) use ($root) {
        return '<a class="' . $class . '" href="' . nb_e($root . $pfad) . '"' . ($an ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    };
    $leiste = '';
    $menue = '';
    foreach ($bereiche as $b) {
        $an = in_array($aktiv, $b[2], true);
        $leiste .= $link('site-nav__link', $b[0], $b[1], $an);
        $menue .= '<li>' . $link('nav-menu__link', $b[0], $b[1], $an) . '</li>';
    }
    $menue .= '<li class="nav-menu__trenner" role="separator"></li>';
    foreach ($extra as $key => $b) {
        $menue .= '<li>' . $link('nav-menu__link nav-menu__link--klein', $b[0], $b[1], $aktiv === $key) . '</li>';
    }
    $version = (int) @filemtime(__DIR__ . '/../nav.js');
    // Glocke: fuehrt zu den Einstellungen (Benachrichtigungen, Meine Zuege); ob sie an
    // sind, setzt dashboard.js auf Start- und Einstellungsseite
    $glocke = '<a class="tool tool--toggle" id="notify-toggle" href="' . nb_e($root) . 'einstellungen/"'
        . ($aktiv === 'einstellungen' ? ' aria-current="page"' : '') . ' title="Benachrichtigungen und Einstellungen">'
        . '<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2Zm7-6.3V11c0-3.3-2-6.1-5-6.8V3.5a2 2 0 1 0-4 0v.7C7 4.9 5 7.7 5 11v4.7L3 17.5V19h18v-1.5l-2-1.8ZM17 16.5H7V11c0-2.8 2.1-5 5-5s5 2.2 5 5v5.5Z"/></svg>'
        . '<span class="visually-hidden" id="notify-label">Benachrichtigungen und Einstellungen</span></a>';

    return '<header class="site-head">'
        . '<div class="wrap site-head__row">'
        . '<a class="site-head__brand" href="' . nb_e($root) . '"><img class="site-head__logo" src="' . nb_e($root) . 'icon-64.png" alt="" width="28" height="28">Bahn-Pinneberg.de</a>'
        . '<nav class="site-nav" aria-label="Bereiche">' . $leiste . '</nav>'
        . '<div class="site-head__tools">' . $tools . $glocke
        . '<details class="nav-menu" id="nav-menu">'
        . '<summary class="tool nav-menu__knopf" title="Men&uuml;">'
        . '<svg class="tool__icon nav-menu__auf" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z"/></svg>'
        . '<svg class="tool__icon nav-menu__zu" viewBox="0 0 24 24" aria-hidden="true"><path d="m6.4 5 5.6 5.6L17.6 5 19 6.4 13.4 12l5.6 5.6-1.4 1.4-5.6-5.6L6.4 19 5 17.6l5.6-5.6L5 6.4 6.4 5Z"/></svg>'
        . '<span class="visually-hidden">Men&uuml;</span></summary>'
        . '<nav class="nav-menu__panel" aria-label="Men&uuml;"><ul class="nav-menu__list">' . $menue . '</ul></nav>'
        . '</details>'
        . '</div>'
        . '</div>'
        . '<script src="' . nb_e($root) . 'nav.js?v=' . $version . '" defer></script>'
        . '</header>';
}

/* ---------- Lage als Markdown (lage.md, fuer KI-Assistenten und Skripte) ---------- */

/** "08:12 Uhr" in deutscher Zeit aus einem ISO-Zeitstempel. */
function nb_md_uhr($iso)
{
    $ts = nb_ts($iso);

    return $ts === null ? '' : nb_fmt_uhr($ts);
}

/** Eine Abfahrtszeile als Markdown-Listenpunkt. */
function nb_md_zug(array $d)
{
    $linie = trim((string) ($d['linie'] ?? ''));
    $ziel = trim((string) ($d['ziel'] ?? ''));
    $kopf = (!empty($d['bus']) ? 'Bus (Schienenersatzverkehr) ' : '') . ($linie !== '' ? $linie . ' ' : '') . ($ziel !== '' ? 'nach ' . $ziel : '');
    $ab = !empty($d['ab']) ? ' ab ' . $d['ab'] : '';
    $teile = [];
    if (!empty($d['ausfall'])) {
        $teile[] = nb_md_uhr($d['zeit'] ?? '') . $ab . ': ' . $kopf . ' fällt aus';
        if (!empty($d['ersatz'])) {
            $teile[] = 'Ersatz ab ' . $d['ersatz']['ab'] . ' um ' . nb_md_uhr($d['ersatz']['zeit'] ?? '');
        }
    } else {
        $zeit = nb_md_uhr($d['zeit'] ?? '');
        $v = (int) ($d['verspaetung'] ?? 0);
        $teile[] = $zeit . ($v > 0 ? ' (+' . $v . ' min)' : '') . $ab . ': ' . $kopf;
        if (!empty($d['gleis'])) {
            $teile[] = 'Gleis ' . $d['gleis'] . (!empty($d['gleis_plan']) && $d['gleis_plan'] !== $d['gleis'] ? ' statt ' . $d['gleis_plan'] : '');
        }
        if (!empty($d['teil']['art']) && !empty($d['teil']['name'])) {
            if (!empty($d['teil']['plan']) && $d['teil']['art'] === 'beginnt' && ($d['ab'] ?? '') === $d['teil']['name']) {
                $teile[] = 'beginnt planmäßig dort';
            } else {
                $teile[] = ($d['teil']['art'] === 'beginnt' ? 'erst ab ' : 'nur bis ') . $d['teil']['name'] . (!empty($d['teil']['plan']) ? ' (planmäßig)' : '');
            }
        }
    }
    if (!empty($d['hinweis'])) {
        $teile[] = trim((string) $d['hinweis']);
    }

    return '- ' . implode('. ', array_map('trim', $teile)) . '.';
}

/**
 * Die ganze Lage als Markdown: Status, aktive Stoerungen, naechste Zuege in beide
 * Richtungen, letzte Eilmeldungen und aufgehobene Stoerungen. $abf ist die Antwort
 * von abf_cached() oder null.
 */
function nb_lage_md(array $data, $abf, $heimat, $now = null, $gf = null)
{
    $now = $now ?? time();
    $site = nordbahn_site_url();
    $name = NORDBAHN_BAHNHOEFE[$heimat]['name'] ?? $heimat;
    $items = nb_items($data);
    $stand = !empty($data['received_at']) ? nb_fmt_wann(nb_ts($data['received_at']), $now) : 'unbekannt';

    $md = "# Bahn-Pinneberg.de: aktuelle Lage\n\n";
    $md .= "Störungen, Abfahrten und Eilmeldungen der Nordbahn-Linien RB61 (Itzehoe – Hamburg Hbf), RB71 (Wrist – Hamburg Hbf) und RB60 (Itzehoe – Hamburg-Altona). Privates Projekt, keine offizielle Seite der Nordbahn. Stand der Störungs- und Eilmeldungsdaten: " . $stand . ".\n\n";

    $md .= "## Lage auf einen Blick\n\n";
    $infra = nb_status_infra($data);
    $eil = nb_status_eil($data, $items);
    $betrieb = nb_status_betrieb($abf, nb_baufahrplan($data, $now));
    $zeile = function ($label, $st) {
        return '- ' . $label . ': ' . $st['title'] . ($st['detail'] !== '' ? ' (' . $st['detail'] . ')' : '') . "\n";
    };
    $md .= $zeile('Infrastruktur', $infra) . $zeile('Eilmeldungen', $eil) . $zeile('Nordbahn ab ' . $name, $betrieb);
    if ($gf !== null) {
        $md .= $zeile('S3 Pinneberg – Hamburg', nb_status_s3($gf));
    }
    $md .= "\n";

    $md .= "## Aktive Infrastrukturstörungen\n\n";
    $teile = nb_stoerungen_teilen($data['stoerungen'] ?? []);
    $aktive = $teile['stoerungen'];
    if (!$aktive) {
        $md .= "Keine aktive Störung laut strecken-info.de.\n\n";
    }
    foreach ($aktive as $s) {
        $titel = $s['ki_titel'] !== '' ? $s['ki_titel'] : $s['titel'];
        $text = $s['ki_text'] !== '' ? $s['ki_text'] : $s['text'];
        $md .= '### ' . $titel . "\n\n";
        if ($text !== '') {
            $md .= $text . "\n\n";
        }
        $md .= '- Seite: ' . nb_ereignis_url('stoerung', $s['key'], $titel) . "\n";
        $zeit = [];
        if (!empty($s['beginn'])) {
            $zeit[] = 'seit ' . nb_fmt_wann(nb_ts($s['beginn']), $now, true);
        }
        if (!empty($s['ende'])) {
            $zeit[] = !empty($s['abgelaufen'])
                ? 'gemeldetes Ende ' . nb_fmt_wann(nb_ts($s['ende']), $now, true) . ' überschritten, dauert an'
                : 'voraussichtlich bis ' . nb_fmt_wann(nb_ts($s['ende']), $now, true);
        }
        if ($zeit) {
            $md .= '- Zeitraum: ' . implode(', ', $zeit) . "\n";
        }
        if (!empty($s['wirkungen'])) {
            $md .= '- Auswirkung: ' . implode(', ', $s['wirkungen']) . "\n";
        }
        $md .= "\n";
    }

    $md .= '## Nächste Züge ab ' . $name . "\n\n";
    if (!$abf || empty($abf['ok']) || empty($abf['richtungen'])) {
        $md .= "Keine aktuellen Abfahrtsdaten.\n\n";
    } else {
        $md .= 'Stand ' . nb_fmt_wann(nb_ts($abf['fetched_at']), $now) . ". Andere Bahnhöfe über `?bhf=KÜRZEL` (siehe llms.txt).\n\n";
        $md .= '### ' . $name . " → Hamburg\n\n";
        $hin = array_slice($abf['richtungen']['hin'] ?? [], 0, 6);
        $md .= ($hin ? implode("\n", array_map('nb_md_zug', $hin)) : '- Keine Abfahrten in der nächsten Zeit.') . "\n\n";
        $md .= '### Hamburg → ' . $name . "\n\n";
        $rueck = array_slice($abf['richtungen']['rueck'] ?? [], 0, 6);
        $md .= ($rueck ? implode("\n", array_map('nb_md_zug', $rueck)) : '- Keine Abfahrten in der nächsten Zeit.') . "\n\n";
    }

    if ($gf !== null) {
        $md .= nb_lage_md_s3($gf, $now);
    }

    foreach ($teile['hinweise'] as $s) {
        if (!isset($hinweisKopf)) {
            $hinweisKopf = true;
            $md .= "### Hinweise ohne spürbare Folgen\n\nLaut strecken-info.de aktiv, für Pendler laut Meldung ohne Auswirkung; zählen nicht als Störung.\n\n";
        }
        $titel = $s['ki_titel'] !== '' ? $s['ki_titel'] : $s['titel'];
        $md .= '- ' . $titel . ' (' . nb_ereignis_url('stoerung', $s['key'], $titel) . ")\n";
    }
    if (isset($hinweisKopf)) {
        $md .= "\n";
    }
    $md .= "## Letzte Eilmeldungen der Nordbahn\n\n";
    $cutoff = $now - 86400;
    $eilmeldungen = array_reverse(array_values(array_filter($items, function ($it) use ($cutoff) {
        return ($it['stand'] ?? $it['ts']) >= $cutoff;
    })));
    $eilmeldungen = array_slice($eilmeldungen, 0, 5);
    if (!$eilmeldungen) {
        $md .= "Keine Eilmeldung in den letzten 24 Stunden.\n\n";
    }
    foreach ($eilmeldungen as $it) {
        $md .= '- ' . nb_fmt_wann($it['stand'] ?? $it['ts'], $now) . ': ' . ($it['title'] !== '' ? $it['title'] : $it['id'])
            . (($it['text'] ?? '') !== '' ? '. ' . $it['text'] : '')
            . ' (' . nb_ereignis_url('eilmeldung', $it['id'], $it['title']) . ')' . "\n";
    }
    if ($eilmeldungen) {
        $md .= "\n";
    }

    $md .= "## Zuletzt aufgehobene Infrastrukturstörungen\n\n";
    $cutoff = $now - 86400;
    $verlauf = array_slice(array_values(array_filter(nb_verlauf_items($data), function ($it) use ($cutoff) {
        return $it['ende'] >= $cutoff;
    })), 0, 5);
    if (!$verlauf) {
        $md .= "Keine aufgehobene Störung in den letzten 24 Stunden.\n\n";
    }
    foreach ($verlauf as $it) {
        $md .= '- ' . nb_fmt_wann($it['start'], $now) . ' ' . nb_fmt_bis($it['start'], $it['ende'], $now) . ' (' . nb_dauer($it['ende'] - $it['start']) . '): ' . $it['titel']
            . ($it['text'] !== '' ? '. ' . $it['text'] : '') . ' (' . nb_ereignis_url('stoerung', $it['key'], $it['titel']) . ')' . "\n";
    }
    if ($verlauf) {
        $md .= "\n";
    }

    $md .= "## Quellen und Links\n\n";
    $md .= "- Website: " . $site . "/\n";
    $md .= "- Beschreibung für Assistenten: " . $site . "/llms.txt\n";
    $md .= "- Archiv: " . $site . "/eilmeldungen/ und " . $site . "/stoerungen/, Statistik: " . $site . "/statistik/\n";
    $md .= "- Quellen: Stationsmonitor der Nordbahn (Abfahrten), nordbahn.de (Eilmeldungen), strecken-info.de der DB InfraGO (Infrastrukturstörungen)" . ($gf !== null ? ', hvv.de über die Geofox-Schnittstelle (S3, HVV-Meldungen)' : '') . ". Verbindliche Auskünfte gibt nur die Nordbahn.\n";

    return $md;
}

/** Abschnitt S3 und HVV-Meldungen fuer lage.md. */
function nb_lage_md_s3($gf, $now)
{
    $md = "## S3 zwischen Pinneberg und Hamburg (HVV)\n\n";
    if (empty($gf['ok']) || !is_array($gf['s3'] ?? null)) {
        return $md . "Keine aktuellen S3-Daten.\n\n";
    }
    $s3 = $gf['s3'];
    $stadt = $gf['stadt']['name'] ?? 'Hamburg Hbf';
    foreach ([GF_PINNEBERG => '### S3 ab Pinneberg → Hamburg', $stadt => '### S3 ab ' . $stadt . ' → Pinneberg'] as $ab => $kopf) {
        $md .= $kopf . "\n\n";
        $n = 0;
        foreach ($s3['abfahrten'] ?? [] as $d) {
            if (($d['ab'] ?? '') !== $ab) {
                continue;
            }
            $n++;
            $zeit = nb_ts($d['zeit'] ?? '');
            $md .= '- ' . ($zeit ? nb_fmt_uhr($zeit) : '--:--') . ': S3 nach ' . $d['ziel']
                . (!empty($d['ausfall']) ? ', fällt aus' : (($d['delay_min'] ?? 0) >= 3 ? ' (+' . (int) $d['delay_min'] . ' min)' : ''))
                . (($d['gleis'] ?? '') !== '' ? '. Gleis ' . $d['gleis'] : '') . ".\n";
        }
        $md .= ($n ? '' : "Keine S3-Abfahrt in den nächsten 60 Minuten gemeldet.\n") . "\n";
    }
    $md .= "### HVV-Meldungen zu S3, RB60, RB61 und RB71\n\n";
    if (empty($s3['meldungen'])) {
        $md .= "Keine HVV-Meldung.\n\n";
    }
    foreach ($s3['meldungen'] ?? [] as $m) {
        $zeitraum = nb_meldung_zeitraum($m, $now);
        $md .= '- ' . (!empty($m['geplant']) ? 'geplant' : 'aktuell') . ($zeitraum !== '' ? ', ' . $zeitraum : '') . (!empty($m['linien']) ? ' (' . implode(', ', $m['linien']) . ')' : '') . ': ' . $m['titel']
            . (($m['text'] ?? '') !== '' ? '. ' . preg_replace('/\s+/', ' ', mb_substr($m['text'], 0, 300)) : '') . "\n";
    }
    if (!empty($s3['meldungen'])) {
        $md .= "\n";
    }

    return $md;
}

require_once __DIR__ . '/zuglog.php';
require_once __DIR__ . '/ereignis.php';
require_once __DIR__ . '/geofox.php';
