<?php
/**
 * Push-Benachrichtigungen: Abos verwalten, Ereignisse aus den Daten ableiten
 * und ueber api/webpush.php verschicken. Wird am Ende von api/push.php
 * aufgerufen, also alle zwei Minuten, wenn n8n neue Daten liefert.
 *
 * Abos liegen in cache/push-subs.json (je Abo: Endpunkt, Schluessel,
 * Heimatbahnhof, Zeitregel, gemerkte Zuege, "nur meine Zuege").
 * cache/push-state.json merkt sich, was schon gemeldet wurde, damit nichts
 * doppelt kommt.
 *
 * Meine Zuege: Wer in der Abfahrtstafel einen Zug merkt, bekommt zu diesem Zug
 * an den gewaehlten Wochentagen ab einer Stunde vor der Abfahrt eine Meldung bei
 * Ausfall, Abfahrt von einem anderen Hamburger Bahnhof, Teilausfall, Bus statt
 * Zug, Verspaetung ab fuenf Minuten (erneut bei 10, 15, 20, 30, 45, 60) und
 * Gleiswechsel. Diese Meldungen kommen unabhaengig von der Zeitregel.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/webpush.php';

const NB_PUSH_SUBS_FILE  = NORDBAHN_CACHE_DIR . '/push-subs.json';
const NB_PUSH_STATE_FILE = NORDBAHN_CACHE_DIR . '/push-state.json';
const NB_PUSH_MAX_SUBS   = 5000;
const NB_PUSH_KI_WARTEN  = 6 * 60;   // Sekunden, die eine Stoerung auf den Pendler-Text wartet
const NB_PUSH_SEEN_KEEP  = 3 * 86400; // Sekunden, die gemeldete Ereignisse gemerkt bleiben
const NB_PUSH_ZUG_FENSTER = 90 * 60;  // nur Ausfaelle in den naechsten 90 Minuten melden
const NB_PUSH_MAX_ZUEGE  = 8;         // gemerkte Zuege je Abo
const NB_PUSH_MEIN_FENSTER = 60 * 60; // gemerkte Zuege ab einer Stunde vor der Abfahrt beobachten
const NB_PUSH_MEIN_STUFEN = [5, 10, 15, 20, 30, 45, 60]; // Verspaetungen (Min.), ab denen (erneut) gemeldet wird

/** VAPID-Konfiguration aus config.php, oder null wenn Web Push nicht eingerichtet ist. */
function nb_push_vapid()
{
    $config = nordbahn_config();
    $private = trim((string) ($config['NORDBAHN_VAPID_PRIVATE'] ?? ''));
    if ($private === '') {
        return null;
    }
    $public = trim((string) ($config['NORDBAHN_VAPID_PUBLIC'] ?? ''));
    if ($public === '') {
        $public = (string) wp_vapid_public_from_private($private);
    }
    if ($public === '') {
        return null;
    }
    $subject = trim((string) ($config['NORDBAHN_VAPID_SUBJECT'] ?? ''));
    if ($subject === '') {
        $subject = 'mailto:hallo@' . nordbahn_site_host();
    }

    return ['private_pem' => $private, 'public' => $public, 'subject' => $subject];
}

/** Oeffentlicher Schluessel fuer die Seite, leer wenn Web Push aus ist. */
function nb_push_public_key()
{
    $v = nb_push_vapid();

    return $v ? $v['public'] : '';
}

/** Fuehrt $fn mit der Abo-Liste aus (unter Dateisperre) und schreibt sie zurueck. */
function nb_push_with_subs(callable $fn)
{
    if (!is_dir(NORDBAHN_CACHE_DIR)) {
        @mkdir(NORDBAHN_CACHE_DIR, 0775, true);
    }
    $lock = @fopen(NB_PUSH_SUBS_FILE . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    flock($lock, LOCK_EX);
    $subs = nordbahn_read_json(NB_PUSH_SUBS_FILE);
    if (!is_array($subs)) {
        $subs = [];
    }
    $result = $fn($subs);
    nordbahn_write_json(NB_PUSH_SUBS_FILE, $subs);
    flock($lock, LOCK_UN);
    fclose($lock);

    return $result;
}

/** Prueft und normalisiert ein Abo aus dem Browser (PushSubscription.toJSON()). */
function nb_push_clean_subscription($raw)
{
    if (!is_array($raw)) {
        return null;
    }
    $endpoint = trim((string) ($raw['endpoint'] ?? ''));
    $p256dh = trim((string) ($raw['keys']['p256dh'] ?? ''));
    $auth = trim((string) ($raw['keys']['auth'] ?? ''));
    $u = parse_url($endpoint);
    if (strlen($endpoint) > 1000 || empty($u['scheme']) || $u['scheme'] !== 'https' || empty($u['host'])) {
        return null;
    }
    if (strlen(wp_b64url_decode($p256dh)) !== 65 || strlen(wp_b64url_decode($auth)) !== 16) {
        return null;
    }

    return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
}

function nb_push_sub_id($endpoint)
{
    return substr(hash('sha256', $endpoint), 0, 32);
}

/** Schluessel eines gemerkten Zugs: Bahnhof, Richtung und Zugnummer. */
function nb_push_zug_key(array $z)
{
    return $z['heimat'] . ':' . $z['richtung'] . ':' . $z['zug'];
}

/**
 * Prueft die gemerkten Zuege aus dem Browser. Je Zug: Bahnhof (heimat),
 * Richtung (hin/rueck), Zugnummer, weitere Nummern desselben Pendelzugs
 * (nummern, z. B. im Baufahrplan; siehe api/fahrplan.php), Linie, Planzeit HH:MM am Abfahrtsbahnhof,
 * Abfahrtsbahnhof in Hamburg (ab, nur rueck), Ziel und Wochentage (1 = Montag,
 * ohne Angabe Mo-Fr). Liefert null, wenn keine Liste kam; dann bleibt die alte.
 */
function nb_push_clean_zuege($raw)
{
    if (!is_array($raw)) {
        return null;
    }
    $str = function ($v) {
        return is_scalar($v) ? (string) $v : '';
    };
    $text = function ($v, $max) use ($str) {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $str($v))), 0, $max);
    };
    $out = [];
    foreach ($raw as $z) {
        if (!is_array($z)) {
            continue;
        }
        $heimat = $str($z['heimat'] ?? '');
        $richtung = $str($z['richtung'] ?? '');
        $zug = $str($z['zug'] ?? '');
        $zeit = $str($z['zeit'] ?? '');
        if (!isset(NORDBAHN_BAHNHOEFE[$heimat]) || !in_array($richtung, ['hin', 'rueck'], true)
            || !preg_match('/^\d{1,6}$/', $zug) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $zeit)) {
            continue;
        }
        $tage = [];
        foreach (is_array($z['tage'] ?? null) ? $z['tage'] : [] as $t) {
            if (is_int($t) || (is_string($t) && ctype_digit($t))) {
                $t = (int) $t;
                if ($t >= 1 && $t <= 7) {
                    $tage[$t] = $t;
                }
            }
        }
        ksort($tage);
        $eintrag = [
            'heimat'   => $heimat,
            'richtung' => $richtung,
            'zug'      => $zug,
            'linie'    => $text($z['linie'] ?? '', 12),
            'zeit'     => $zeit,
            'ab'       => $richtung === 'rueck' ? $text($z['ab'] ?? '', 30) : '',
            'ziel'     => $text($z['ziel'] ?? '', 60),
            'tage'     => $tage ? array_values($tage) : [1, 2, 3, 4, 5],
            // eigene Nummer zuerst, dazu hoechstens vier Varianten
            'nummern'  => array_slice(array_values(array_unique(array_merge([$zug], array_filter(
                is_array($z['nummern'] ?? null) ? array_map($str, $z['nummern']) : [],
                function ($n) { return preg_match('/^\d{1,6}$/', $n) === 1; }
            )))), 0, 5),
        ];
        $out[nb_push_zug_key($eintrag)] = $out[nb_push_zug_key($eintrag)] ?? $eintrag;
        if (count($out) >= NB_PUSH_MAX_ZUEGE) {
            break;
        }
    }

    return array_values($out);
}

/**
 * Legt ein Abo an oder aktualisiert Heimat, Zeitregel, gemerkte Zuege und
 * "nur meine Zuege". $alt ist der alte Endpunkt, wenn der Browser das Abo
 * erneuert hat (pushsubscriptionchange). $zuege und $nur bleiben ohne Angabe
 * (null), wie sie waren.
 */
function nb_push_subscribe(array $sub, $heimat, $zeit, $alt = '', ?array $zuege = null, $nur = null)
{
    $heimat = isset(NORDBAHN_BAHNHOEFE[$heimat]) ? $heimat : null;
    $zeit = $zeit === 'immer' ? 'immer' : ($zeit === 'pendel' ? 'pendel' : null);

    return nb_push_with_subs(function (array &$subs) use ($sub, $heimat, $zeit, $alt, $zuege, $nur) {
        $id = nb_push_sub_id($sub['endpoint']);
        $vorher = $subs[$id] ?? null;
        if ($vorher === null && $alt !== '' && isset($subs[nb_push_sub_id($alt)])) {
            $vorher = $subs[nb_push_sub_id($alt)];
            unset($subs[nb_push_sub_id($alt)]);
        }
        if ($vorher === null && count($subs) >= NB_PUSH_MAX_SUBS) {
            return false;
        }
        $subs[$id] = [
            'endpoint' => $sub['endpoint'],
            'p256dh'   => $sub['p256dh'],
            'auth'     => $sub['auth'],
            'heimat'   => $heimat ?? ($vorher['heimat'] ?? NORDBAHN_HEIMAT_DEFAULT),
            'zeit'     => $zeit ?? ($vorher['zeit'] ?? 'pendel'),
            'zuege'    => $zuege ?? ($vorher['zuege'] ?? []),
            'nur'      => $nur !== null ? (bool) $nur : !empty($vorher['nur']),
            'seit'     => $vorher['seit'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'stand'    => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return true;
    });
}

function nb_push_unsubscribe($endpoint)
{
    return nb_push_with_subs(function (array &$subs) use ($endpoint) {
        unset($subs[nb_push_sub_id($endpoint)]);

        return true;
    });
}

/** Mo-Fr 6-9 und 16-19 Uhr in Deutschland, wie inPendelzeit im Script. */
function nb_push_in_pendelzeit($ts)
{
    $dt = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $tag = (int) $dt->format('N');
    $h = (int) $dt->format('G');

    return $tag <= 5 && (($h >= 6 && $h < 9) || ($h >= 16 && $h < 19));
}

/**
 * Ereignisse aus altem und neuem Datenpaket: neue Stoerungen (sobald der
 * Pendler-Text da ist, spaetestens nach NB_PUSH_KI_WARTEN), aufgehobene
 * Stoerungen und neue Eilmeldungen (ohne stumme). $state wird fortgeschrieben.
 *
 * Hinweise ohne spuerbare Folgen (nb_stoerung_hinweis) loesen nichts aus. Nur
 * ein Wechsel der Folgen zaehlt: wird aus einem Hinweis eine Stoerung, gilt sie
 * als neu; wird aus einer gemeldeten Stoerung ein Hinweis, geht die Entwarnung
 * sofort raus, und die spaetere Aufhebung wird nicht noch einmal gemeldet.
 */
function nb_push_events_data(array $alt, array $neu, array &$state, $now)
{
    $events = [];
    $state['pending'] = is_array($state['pending'] ?? null) ? $state['pending'] : [];
    $state['hinweis'] = is_array($state['hinweis'] ?? null) ? $state['hinweis'] : [];
    $altKeys = [];
    foreach ($alt['stoerungen'] ?? [] as $s) {
        if (!empty($s['key'])) {
            $altKeys[$s['key']] = $s;
        }
    }
    $neuKeys = [];
    foreach ($neu['stoerungen'] ?? [] as $s) {
        if (empty($s['key'])) {
            continue;
        }
        $neuKeys[$s['key']] = $s;
        $titel = !empty($s['ki_titel']) ? $s['ki_titel'] : ($s['titel'] ?? 'Störung');
        $warHinweis = !empty($state['hinweis'][$s['key']]);
        if (nb_stoerung_hinweis($s)) {
            unset($state['pending'][$s['key']]);
            $state['hinweis'][$s['key']] = true;
            if (!$warHinweis && !empty($state['sent']['stoerung:' . $s['key']])) {
                // Gemeldete Stoerung ist nur noch ein Hinweis: das ist die Entwarnung
                $events[] = [
                    'id'    => 'aufgehoben:' . $s['key'],
                    'title' => 'Keine Folgen mehr: ' . $titel,
                    'body'  => !empty($s['ki_text']) ? $s['ki_text'] : 'Für Pendler laut strecken-info.de ohne Auswirkung.',
                    'url'   => './',
                ];
            }
            continue;
        }
        unset($state['hinweis'][$s['key']]);
        $pending = $state['pending'][$s['key']] ?? null;
        if ((!isset($altKeys[$s['key']]) || $warHinweis) && $pending === null) {
            $state['pending'][$s['key']] = $now;
            $pending = $now;
        }
        if ($pending === null) {
            continue;
        }
        $ki = !empty($s['ki_titel']) || !empty($s['ki_text']);
        if ($ki || $now - $pending >= NB_PUSH_KI_WARTEN) {
            unset($state['pending'][$s['key']]);
            $events[] = [
                // Nach einem Hinweis eine neue Kennung, sonst wuerde die Sperre gegen Doppelte greifen
                'id'    => 'stoerung:' . $s['key'] . ($warHinweis && !empty($state['sent']['stoerung:' . $s['key']]) ? ':' . $now : ''),
                'title' => 'Störung: ' . $titel,
                'body'  => !empty($s['ki_text']) ? $s['ki_text'] : (string) ($s['text'] ?? ''),
                'url'   => './',
            ];
        }
    }
    foreach ($altKeys as $key => $s) {
        if (isset($neuKeys[$key])) {
            continue;
        }
        unset($state['pending'][$key], $state['hinweis'][$key]);
        if (empty($state['sent']['stoerung:' . $key])) {
            continue; // nie gemeldet (oder nur ein Hinweis), dann auch keine Entwarnung
        }
        $events[] = [
            'id'    => 'aufgehoben:' . $key,
            'title' => 'Aufgehoben: ' . (!empty($s['ki_titel']) ? $s['ki_titel'] : ($s['titel'] ?? 'Störung')),
            'body'  => 'Die Störung ist laut strecken-info.de beendet.',
            'url'   => './',
        ];
    }

    $altIds = [];
    foreach ($alt['eilmeldungen'] ?? [] as $e) {
        if (!empty($e['id'])) {
            $altIds[$e['id']] = true;
        }
    }
    if ($altIds) { // ohne alte Historie waere alles "neu"
        foreach ($neu['eilmeldungen'] ?? [] as $e) {
            if (empty($e['id']) || isset($altIds[$e['id']]) || !empty($e['stumm'])) {
                continue;
            }
            $events[] = [
                'id'    => 'eil:' . $e['id'],
                'title' => 'Eilmeldung: ' . (!empty($e['ki_titel']) ? $e['ki_titel'] : ($e['title'] ?? 'Nordbahn')),
                'body'  => (string) ($e['ki_text'] ?? ''),
                'url'   => './',
            ];
        }
    }

    return $events;
}

/** Zugausfaelle am Heimatbahnhof aus einer Abfahrten-Antwort, wie collectEvents im Script. */
function nb_push_events_abfahrten($result, $heimat, $now)
{
    $events = [];
    if (!is_array($result) || empty($result['richtungen'])) {
        return $events;
    }
    $name = NORDBAHN_BAHNHOEFE[$heimat]['name'] ?? $heimat;
    $ast = NORDBAHN_BAHNHOEFE[$heimat]['ast'] ?? 'stamm';
    $rueck = 'Hamburg → ' . ($ast === 'itzehoe' ? 'Itzehoe' : ($ast === 'wrist' ? 'Wrist' : 'Wrist / Itzehoe'));
    foreach (['hin', 'rueck'] as $dir) {
        foreach (array_slice($result['richtungen'][$dir] ?? [], 0, 6) as $d) {
            if (empty($d['ausfall'])) {
                continue;
            }
            $ts = strtotime((string) ($d['zeit'] ?? ''));
            if ($ts === false || $ts < $now - 300 || $ts > $now + NB_PUSH_ZUG_FENSTER) {
                continue;
            }
            $uhr = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');
            $richtung = $dir === 'hin' ? $name . ' → Hamburg' : $rueck;
            $body = $richtung . (!empty($d['ab']) ? ' ab ' . $d['ab'] : '') . (!empty($d['hinweis']) ? '. ' . $d['hinweis'] : '');
            if (!empty($d['ersatz']['ab']) && !empty($d['ersatz']['zeit'])) {
                $et = strtotime($d['ersatz']['zeit']);
                $body .= '. Fährt stattdessen ab ' . $d['ersatz']['ab'] . ($et ? ' um ' . (new DateTime('@' . $et))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i') . ' Uhr' : '');
            }
            $events[] = [
                'id'     => 'zug:' . $heimat . ':' . date('Y-m-d', $ts) . ':' . ($d['zug'] !== '' ? $d['zug'] : ($d['linie'] . $uhr)),
                'title'  => 'Zugausfall ' . $d['linie'] . ' um ' . $uhr . ' Uhr',
                'body'   => $body,
                'url'    => './',
                'heimat' => $heimat,
            ];
        }
    }

    return $events;
}

/** Uhrzeit HH:MM in deutscher Zeit zu einem Zeitstempel. */
function nb_push_uhr($ts)
{
    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i');
}

/**
 * Sucht einen gemerkten Zug in der Abfahrten-Antwort seines Bahnhofs: erst ueber
 * die Zugnummer oder eine seiner Varianten (nummern; bei zwei Zeilen derselben Nummer, Ausfall am Hbf und Ersatz ab
 * Altona, die vom gemerkten Startbahnhof), sonst ueber Linie und Planzeit (neue
 * Zugnummer nach dem Fahrplanwechsel). Wer am Dammtor einsteigt, bekommt die
 * Werte des Dammtor-Halts.
 */
function nb_push_finde_zug($result, array $z)
{
    $rows = is_array($result) ? ($result['richtungen'][$z['richtung']] ?? []) : [];
    $treffer = null;
    foreach (is_array($rows) ? $rows : [] as $d) {
        if (!in_array((string) ($d['zug'] ?? ''), $z['nummern'] ?? [$z['zug']], true)) {
            continue;
        }
        $gleichesAb = (string) ($d['ab'] ?? '') === $z['ab'] || ($z['ab'] === 'Dammtor' && ($d['ab'] ?? '') === 'Hbf');
        if ($treffer === null || ($gleichesAb && empty($d['ersatz_fuer']))) {
            $treffer = $d;
        }
    }
    if ($treffer === null) {
        foreach (is_array($rows) ? $rows : [] as $d) {
            $plan = strtotime((string) ($d['zeit_plan'] ?? $d['zeit'] ?? ''));
            if ($plan !== false && nb_push_uhr($plan) === $z['zeit'] && (string) ($d['linie'] ?? '') === $z['linie'] && empty($d['ersatz_fuer'])) {
                $treffer = $d;
                break;
            }
        }
    }
    if ($treffer !== null && $z['ab'] === 'Dammtor' && ($treffer['ab'] ?? '') === 'Hbf' && !empty($treffer['dammtor']) && empty($treffer['ausfall'])) {
        $dt = $treffer['dammtor'];
        $treffer = array_merge($treffer, [
            'zeit' => $dt['zeit'], 'ist' => $dt['ist'], 'verspaetung' => $dt['verspaetung'],
            'gleis' => $dt['gleis'], 'gleis_plan' => $dt['gleis_plan'], 'ausfall' => !empty($dt['ausfall']), 'ab' => 'Dammtor',
        ]);
    }

    return $treffer;
}

/**
 * Meldungen zu gemerkten Zuegen eines Bahnhofs ($zuege: Schluessel => Zug).
 * Je Zug und Tag merkt sich $state['sent'] die gemeldeten Teile (Ausfall,
 * Ersatz, Teilausfall, Bus, Verspaetungsstufe, Gleis). Ein Ereignis entsteht
 * nur, wenn ein Teil neu ist, und beschreibt dann die ganze Lage des Zugs. Alle
 * Meldungen zu einem Zug und Tag tragen denselben tag, die neue ersetzt also die
 * alte auf dem Geraet. Eine Verspaetung meldet sich erneut erst ab der naechsten
 * Stufe, kleiner werdende Verspaetungen bleiben still.
 */
function nb_push_events_meine($result, array $zuege, array $state, $now)
{
    $events = [];
    $sent = is_array($state['sent'] ?? null) ? $state['sent'] : [];
    foreach ($zuege as $key => $z) {
        $d = nb_push_finde_zug($result, $z);
        if ($d === null) {
            continue;
        }
        $zeit = strtotime((string) ($d['zeit'] ?? ''));
        $plan = strtotime((string) ($d['zeit_plan'] ?? $d['zeit'] ?? ''));
        if ($zeit === false || $plan === false) {
            continue;
        }
        $ausfall = !empty($d['ausfall']);
        $ab = $ausfall ? $zeit + 5 * 60 : (strtotime((string) ($d['ist'] ?? '')) ?: $zeit);
        if ($ab < $now - 60 || min($zeit, $plan) > $now + NB_PUSH_MEIN_FENSTER) {
            continue; // schon weg oder noch zu weit hin
        }
        $berlin = (new DateTime('@' . $plan))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $prefix = 'mein:' . $key . ':' . $berlin->format('Y-m-d') . ':';
        $uhr = $berlin->format('H:i');
        $linie = (string) ($d['linie'] ?? $z['linie']);
        $teil = is_array($d['teil'] ?? null) && empty($d['teil']['plan']) ? $d['teil'] : null;
        $v = $ausfall ? 0 : (int) ($d['verspaetung'] ?? 0);
        $gleis = (string) ($d['gleis'] ?? '');
        $gleisPlan = (string) ($d['gleis_plan'] ?? '');

        // Teile der Lage, wichtigster zuerst: [Kennung, Titel]
        $teile = [];
        if ($ausfall && !empty($d['ersatz']['ab'])) {
            $teile[] = ['ersatz:' . $d['ersatz']['ab'], $linie . ' ' . $uhr . ' fährt heute ab ' . $d['ersatz']['ab']];
        } elseif ($ausfall && $teil !== null && $teil['art'] === 'beginnt') {
            $teile[] = ['teil:beginnt:' . $teil['id'], $linie . ' ' . $uhr . ' fährt erst ab ' . $teil['name']];
        } elseif ($ausfall) {
            $teile[] = ['ausfall', $linie . ' um ' . $uhr . ' fällt aus'];
        } elseif ($teil !== null) {
            $teile[] = ['teil:' . $teil['art'] . ':' . $teil['id'], $teil['art'] === 'beginnt'
                ? $linie . ' ' . $uhr . ' startet erst in ' . $teil['name']
                : $linie . ' um ' . $uhr . ' endet heute in ' . $teil['name']];
        }
        if (!empty($d['bus'])) {
            $teile[] = ['bus', $linie . ' um ' . $uhr . ': Bus statt Zug'];
        }
        $stufe = 0;
        foreach (NB_PUSH_MEIN_STUFEN as $s) {
            if ($v >= $s) {
                $stufe = $s;
            }
        }
        if ($stufe > 0) {
            $hoeher = false;
            foreach (NB_PUSH_MEIN_STUFEN as $s) {
                $hoeher = $hoeher || ($s >= $stufe && !empty($sent[$prefix . 'plus:' . $s]));
            }
            $teile[] = ['plus:' . $stufe, $linie . ' um ' . $uhr . ': +' . $v . ' Min.', $hoeher];
        }
        if (!$ausfall && $gleisPlan !== '' && $gleis !== '') {
            $teile[] = ['gleis:' . $gleis, $linie . ' um ' . $uhr . ' heute von Gleis ' . $gleis];
        }

        $neu = array_values(array_filter($teile, function ($t) use ($sent, $prefix) {
            return empty($sent[$prefix . $t[0]]) && empty($t[2]);
        }));
        if (!$neu) {
            continue;
        }

        // Text: Strecke, dann alles, was zur Lage gehoert
        $heimatName = NORDBAHN_BAHNHOEFE[$z['heimat']]['name'] ?? $z['heimat'];
        if ($z['richtung'] === 'hin') {
            $strecke = $heimatName . ' → ' . ($z['ziel'] !== '' ? $z['ziel'] : 'Hamburg');
        } else {
            $start = $z['ab'] !== '' ? $z['ab'] : (string) ($d['ab'] ?? '');
            $strecke = (in_array($start, ['Hbf', 'Altona', 'Dammtor'], true) ? 'Hamburg ' : '') . $start . ' → ' . $heimatName;
        }
        $saetze = ['Dein Zug ' . $strecke];
        if ($ausfall && !empty($d['ersatz']['ab'])) {
            $e = $d['ersatz'];
            $et = strtotime((string) ($e['zeit'] ?? ''));
            $saetze[] = 'Fällt ab ' . ($d['ab'] ?? 'Hbf') . ' aus, fährt stattdessen ab ' . $e['ab']
                . ($et ? ' um ' . nb_push_uhr($et) . ' Uhr' : '')
                . ((int) ($e['verspaetung'] ?? 0) >= 3 ? ' (+' . (int) $e['verspaetung'] . ')' : '')
                . (!empty($e['gleis']) ? ', Gleis ' . $e['gleis'] : '');
        } elseif ($ausfall && $teil !== null && $teil['art'] === 'beginnt') {
            $saetze[] = 'Startet heute erst in ' . $teil['name'];
        } elseif ($ausfall) {
            $saetze[] = 'Fällt heute aus';
        } elseif ($teil !== null && $teil['art'] === 'beginnt') {
            $saetze[] = 'Abfahrt dort um ' . nb_push_uhr($zeit) . ' Uhr' . ($gleis !== '' ? ', Gleis ' . $gleis : '');
        } elseif ($teil !== null) {
            $saetze[] = 'Fährt nur bis ' . $teil['name'] . ($teil['id'] === 'AP' ? ', weiter mit der S-Bahn' : '');
        }
        if (!$ausfall && $v >= 3) {
            $saetze[] = 'Voraussichtlich ' . nb_push_uhr($ab) . ' Uhr statt ' . nb_push_uhr($zeit) . ' (+' . $v . ')';
        }
        if (!$ausfall && $gleisPlan !== '' && $gleis !== '') {
            $saetze[] = 'Heute Gleis ' . $gleis . ' statt ' . $gleisPlan;
        }
        if (!empty($d['hinweis'])) {
            $saetze[] = rtrim((string) $d['hinweis'], '. ');
        }

        $events[] = [
            'id'        => $prefix . implode('+', array_column($neu, 0)),
            'teile'     => array_map(function ($t) use ($prefix) { return $prefix . $t[0]; }, $neu),
            'tag'       => rtrim($prefix, ':'),
            'title'     => $neu[0][1],
            'body'      => implode('. ', $saetze) . '.',
            'url'       => './',
            'mein'      => $key,
            'wochentag' => (int) $berlin->format('N'),
        ];
    }

    return $events;
}

/**
 * Ein Durchlauf: Ereignisse bestimmen, an passende Abos schicken, Zustand
 * fortschreiben. $abfahrten liefert je Heimat-ID die Abfahrten-Antwort
 * (Standard: abf_cached), $sender verschickt (Standard: wp_send).
 * Liefert eine kurze Bilanz.
 */
function nb_push_run(array $alt, array $neu, $now = null, callable $abfahrten = null, callable $sender = null)
{
    return nb_push_run_mit($alt, $neu, $now, $abfahrten, $sender, nb_push_vapid());
}

/** Wie nb_push_run, mit uebergebener VAPID-Konfiguration (Tests). */
function nb_push_run_mit(array $alt, array $neu, $now, ?callable $abfahrten, ?callable $sender, $vapid)
{
    $now = $now ?? time();
    $subs = nordbahn_read_json(NB_PUSH_SUBS_FILE);
    if ($vapid === null || !is_array($subs) || !$subs) {
        return ['abos' => 0, 'ereignisse' => 0, 'gesendet' => 0];
    }
    $state = nordbahn_read_json(NB_PUSH_STATE_FILE);
    if (!is_array($state)) {
        $state = [];
    }
    $state['sent'] = is_array($state['sent'] ?? null) ? $state['sent'] : [];
    foreach ($state['sent'] as $id => $ts) {
        if ($now - (int) $ts > NB_PUSH_SEEN_KEEP) {
            unset($state['sent'][$id]);
        }
    }

    $events = nb_push_events_data($alt, $neu, $state, $now);

    // Bahnhoefe fuer Ausfaelle am Heimatbahnhof (Abos ohne "nur meine Zuege") und
    // die gemerkten Zuege je Bahnhof; jede Tafel wird nur einmal gelesen
    $heimaten = [];
    $meine = [];
    $subZuege = [];
    foreach ($subs as $id => $sub) {
        if (empty($sub['nur'])) {
            $heimaten[$sub['heimat'] ?? NORDBAHN_HEIMAT_DEFAULT] = true;
        }
        foreach (is_array($sub['zuege'] ?? null) ? $sub['zuege'] : [] as $z) {
            if (!is_array($z) || !isset($z['heimat'], $z['richtung'], $z['zug'])) {
                continue;
            }
            $key = nb_push_zug_key($z);
            $z += ['linie' => '', 'zeit' => '', 'ab' => '', 'ziel' => '', 'tage' => [1, 2, 3, 4, 5], 'nummern' => [$z['zug']]];
            $meine[$z['heimat']][$key] = $meine[$z['heimat']][$key] ?? $z;
            $subZuege[$id][$key] = array_map('intval', (array) $z['tage']);
        }
    }
    $abfahrten = $abfahrten ?? function ($heimat) {
        if (!defined('NORDBAHN_ABF_LIB')) {
            define('NORDBAHN_ABF_LIB', true);
        }
        require_once __DIR__ . '/abfahrten.php';

        return abf_cached($heimat);
    };
    foreach (array_keys($heimaten + $meine) as $heimat) {
        if (!isset(NORDBAHN_BAHNHOEFE[$heimat])) {
            continue;
        }
        $result = $abfahrten($heimat);
        if (isset($heimaten[$heimat])) {
            foreach (nb_push_events_abfahrten($result, $heimat, $now) as $ev) {
                $events[] = $ev;
            }
        }
        foreach (nb_push_events_meine($result, $meine[$heimat] ?? [], $state, $now) as $ev) {
            $events[] = $ev;
        }
    }

    $events = array_values(array_filter($events, function ($ev) use ($state) {
        return empty($state['sent'][$ev['id']]);
    }));

    $sender = $sender ?? 'wp_send';
    $gesendet = 0;
    $tot = [];
    foreach ($events as $ev) {
        $state['sent'][$ev['id']] = $now;
        foreach ($ev['teile'] ?? [] as $teil) {
            $state['sent'][$teil] = $now;
        }
        $payload = json_encode([
            'title' => mb_substr($ev['title'], 0, 120),
            'body'  => mb_substr($ev['body'], 0, 400),
            'url'   => $ev['url'],
            'tag'   => $ev['tag'] ?? $ev['id'],
            'ts'    => $now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subs as $id => $sub) {
            if (isset($tot[$id])) {
                continue;
            }
            if (isset($ev['mein'])) {
                // Gemerkter Zug: nur wer ihn gemerkt hat, an seinen Wochentagen, ohne Zeitregel
                if (!in_array($ev['wochentag'], $subZuege[$id][$ev['mein']] ?? [], true)) {
                    continue;
                }
            } elseif (!empty($sub['nur'])) {
                continue;
            } elseif (!empty($ev['heimat']) && ($sub['heimat'] ?? '') !== $ev['heimat']) {
                continue;
            } elseif (($sub['zeit'] ?? 'pendel') === 'pendel' && !nb_push_in_pendelzeit($now)) {
                continue;
            }
            $r = $sender($sub, $payload, $vapid);
            $status = (int) ($r['status'] ?? 0);
            if ($status === 404 || $status === 410) {
                $tot[$id] = true;
            } elseif ($status >= 200 && $status < 300) {
                $gesendet++;
            }
        }
    }
    if ($tot) {
        nb_push_with_subs(function (array &$subs) use ($tot) {
            foreach (array_keys($tot) as $id) {
                unset($subs[$id]);
            }

            return true;
        });
    }
    nordbahn_write_json(NB_PUSH_STATE_FILE, $state);

    return ['abos' => count($subs), 'ereignisse' => count($events), 'gesendet' => $gesendet, 'entfernt' => count($tot)];
}
