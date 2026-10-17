<?php
/**
 * Bausteine fuer die Tests: kleine Zusicherungen, XML-Fixtures fuer den
 * Nordbahn-Stationsmonitor (?bhf=) und die Zugauskunft (?zug=) sowie ein
 * Fetch-Stub, der statt des Netzes diese Fixtures liefert.
 *
 * Alle Tests rechnen mit einer festen Uhrzeit (T_NOW), die Fixtures nennen
 * ihre Zeiten absolut. So ist ein Test morgen noch derselbe wie heute.
 */

class TestFailure extends Exception
{
}

const T_NOW = '2026-10-15 10:00:00';

function t_now($zeit = T_NOW)
{
    return new DateTime($zeit, new DateTimeZone('Europe/Berlin'));
}

function t_reset()
{
    @unlink(ABF_ZUG_CACHE);
}

function t_assert($ok, $msg)
{
    if (!$ok) {
        throw new TestFailure($msg);
    }
}

function t_eq($expected, $actual, $msg)
{
    if ($expected !== $actual) {
        throw new TestFailure($msg . "\nerwartet: " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\nist:      " . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

function t_contains($needle, $haystack, $msg)
{
    if (strpos((string) $haystack, $needle) === false) {
        throw new TestFailure($msg . "\n„" . $needle . "“ fehlt in: " . json_encode($haystack, JSON_UNESCAPED_UNICODE));
    }
}

function t_h($s)
{
    return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function t_b($b)
{
    return $b ? 'true' : 'false';
}

/**
 * Abfahrtstafel eines Bahnhofs wie xml_v3?bhf=ID. Jede Abfahrt ist ein Array,
 * fehlende Felder bekommen den Normalfall: puenktlicher RB 71 nach Hamburg Hbf.
 */
function t_tafel($name, array $abfahrten, ?DateTime $now = null)
{
    $now = $now ?? t_now();
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><auskunft><name>' . t_h($name) . '</name>'
        . '<stand>' . $now->format('H:i') . '</stand><standsek>' . $now->format('H:i:s') . '</standsek>';
    foreach ($abfahrten as $a) {
        $a += [
            'zeit' => '10:18', 'zug' => '81871', 'linie' => 'RB 71', 'ziel' => ['AH', 'Hamburg Hbf'],
            'gleis' => '2', 'gleiswechsel' => '', 'ausfall' => false, 'prognosemin' => 0, 'prognose' => 'pünktlich',
            'wagen' => 1, 'sev' => false, 'freitext' => '', 'zuglaufinfo' => false, 'ursachetext' => '',
        ];
        $xml .= '<abfahrt>'
            . '<zeit>' . $a['zeit'] . '</zeit><zeitsek>' . $a['zeit'] . ':00</zeitsek>'
            . '<zeitstempel>' . $now->format('Y-m-d') . 'T' . $a['zeit'] . 'Z</zeitstempel>'
            . '<zug>' . $a['zug'] . '</zug><linie>' . t_h($a['linie']) . '</linie>'
            . '<ziel id="' . $a['ziel'][0] . '">' . t_h($a['ziel'][1]) . '</ziel>'
            . '<prognose>' . t_h($a['ausfall'] ? 'Fällt aus' : $a['prognose']) . '</prognose><prognosemin>' . (int) $a['prognosemin'] . '</prognosemin>'
            . '<gleis>' . $a['gleis'] . '</gleis>'
            . ($a['gleiswechsel'] !== '' ? '<gleiswechsel>' . $a['gleiswechsel'] . '</gleiswechsel>' : '')
            . '<ausfall>' . t_b($a['ausfall']) . '</ausfall><zuglaufinfo>' . t_b($a['zuglaufinfo']) . '</zuglaufinfo>'
            . '<istBus>false</istBus><istZug>true</istZug><sev>' . t_b($a['sev']) . '</sev>'
            . ($a['ursachetext'] !== '' ? '<ursachetext>' . t_h($a['ursachetext']) . '</ursachetext>' : '')
            . '<freitext>' . t_h($a['freitext']) . '</freitext>'
            . '<fahrzeuge>' . str_repeat('<fahrzeug><wagennr>94 80 1430 000-0</wagennr><position>1</position></fahrzeug>', (int) $a['wagen']) . '</fahrzeuge>'
            . '</abfahrt>';
    }

    return $xml . '</auskunft>';
}

/**
 * Zugauskunft wie xml_v3?zug=NR. Ein Halt ist [id, name, ankunft, abfahrt, optionen]
 * mit optionen: ausfall (bool), an_prog / ab_prog (Prognosetexte), gleis.
 * Bei einem Ausfall stehen beide Prognosen auf "Halt entfällt!", sofern nicht
 * anders angegeben; so meldet es die Nordbahn fuer einen Durchfahrtshalt.
 */
function t_zug($nr, $linie, $ursache, array $halte, $zuglauf = '')
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><auskunft><produkt>DRB</produkt>'
        . '<zug>' . $nr . '</zug><linie>' . t_h($linie) . '</linie><stand>10:00 AM</stand><standsek>10:00:00</standsek>';
    if ($zuglauf !== '') {
        $xml .= '<zuglaufinfotext>' . t_h($zuglauf) . '<linktext>Hier gelangen Sie zum vollständigen Ersatzfahrplan.</linktext></zuglaufinfotext>';
    }
    $xml .= '<istBus>false</istBus><istZug>true</istZug><ursachetext>' . t_h($ursache) . '</ursachetext><ursacheris>' . t_h($ursache) . '</ursacheris>';
    foreach ($halte as $h) {
        [$id, $name, $an, $ab] = $h;
        $o = ($h[4] ?? []) + ['ausfall' => false, 'an_prog' => null, 'ab_prog' => null, 'gleis' => ''];
        $anProg = $o['an_prog'] ?? ($o['ausfall'] ? 'Halt entfällt!' : ($an !== '' ? 'pünktlich' : ' '));
        $abProg = $o['ab_prog'] ?? ($o['ausfall'] ? 'Halt entfällt!' : ($ab !== '' ? 'pünktlich' : ' '));
        $xml .= '<halt><bhf id="' . $id . '">' . t_h($name) . '</bhf>'
            . ($an !== '' ? '<ankunft>' . $an . '</ankunft><ankunftsek>' . $an . ':00</ankunftsek>' : '')
            . ($ab !== '' ? '<abfahrt>' . $ab . '</abfahrt><abfahrtsek>' . $ab . ':00</abfahrtsek>' : '')
            . '<anprognose>' . t_h($anProg) . '</anprognose><abprognose>' . t_h($abProg) . '</abprognose>'
            . '<anprognosemin>0</anprognosemin><abprognosemin>0</abprognosemin>'
            . ($o['gleis'] !== '' ? '<gleis>' . $o['gleis'] . '</gleis>' : '')
            . '<datum>15.10.2026</datum><ursacheris>' . ($o['ausfall'] ? t_h($ursache) : '') . '</ursacheris>'
            . '<ausfall>' . t_b($o['ausfall']) . '</ausfall><sev>false</sev></halt>';
    }

    return $xml . '</auskunft>';
}

/**
 * Fetch-Stub: $quellen ist ['APD' => xml, 'AH' => xml, …, 'zug:81871' => xml].
 * Fehlt ein Eintrag, antwortet der Stub mit null (wie ein Netzfehler).
 * $calls sammelt die abgefragten URLs.
 */
function t_fetch(array $quellen, ?array &$calls = null)
{
    $calls = [];

    return function ($url) use ($quellen, &$calls) {
        $calls[] = $url;
        if (preg_match('/[?&]zug=(\d+)/', $url, $m)) {
            return $quellen['zug:' . $m[1]] ?? null;
        }
        if (preg_match('/[?&]bhf=([A-Z]+)/', $url, $m)) {
            return $quellen[$m[1]] ?? null;
        }

        return null;
    };
}

/** Baut die Abfahrten aus Fixtures, wie api/abfahrten es mit dem Netz taete. */
function t_build(array $quellen, $heimat = 'APD', ?DateTime $now = null, ?array &$calls = null)
{
    return abf_build(t_fetch($quellen, $calls), $heimat, $now ?? t_now());
}

/** Zeile einer Richtung nach Zugnummer (erste Fundstelle). */
function t_zeile($result, $richtung, $zug)
{
    foreach ($result['richtungen'][$richtung] ?? [] as $row) {
        if ($row['zug'] === (string) $zug) {
            return $row;
        }
    }
    throw new TestFailure("Zug $zug fehlt in Richtung $richtung: " . json_encode(t_zuege($result, $richtung)));
}

/** Zugnummern einer Richtung in Reihenfolge. */
function t_zuege($result, $richtung)
{
    return array_map(function ($r) {
        return $r['zug'];
    }, $result['richtungen'][$richtung] ?? []);
}

/** Uhrzeit "HH:MM" aus einem ISO-Zeitstempel der Antwort. */
function t_uhr($iso)
{
    return $iso === null ? null : substr($iso, 11, 5);
}

/**
 * Standardtag: zwei Zuege ab Prisdorf nach Hamburg, am Hbf ein RB 61 nach
 * Itzehoe und ein RB 71 nach Wrist (beide mit Dammtor-Halt), in Altona ein
 * RB 60 nach Elmshorn. Tests ueberschreiben einzelne Quellen.
 */
function t_standard(array $override = [])
{
    return $override + [
        'AP'  => t_tafel('Pinneberg', []), // Startbahnhof: nachts beginnen hier Zuege, tagsueber leer
        'APD' => t_tafel('Prisdorf', [
            ['zeit' => '10:18', 'zug' => '81871', 'linie' => 'RB 71'],
            ['zeit' => '10:40', 'zug' => '75515', 'linie' => 'RB 61', 'wagen' => 2],
            ['zeit' => '10:21', 'zug' => '81874', 'linie' => 'RB 71', 'ziel' => ['AWST', 'Wrist'], 'gleis' => '1'],
        ]),
        'AH' => t_tafel('Hamburg Hbf', [
            ['zeit' => '10:56', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '13', 'wagen' => 2],
            ['zeit' => '11:17', 'zug' => '81874', 'linie' => 'RB 71', 'ziel' => ['AWST', 'Wrist'], 'gleis' => '13'],
        ]),
        'AA' => t_tafel('Hamburg-Altona', [
            ['zeit' => '10:22', 'zug' => '28542', 'linie' => 'RB 60', 'ziel' => ['AEL', 'Elmshorn'], 'gleis' => '7'],
        ]),
        'ADF' => t_tafel('Hamburg Dammtor', [
            ['zeit' => '10:59', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '3', 'wagen' => 2],
            ['zeit' => '11:21', 'zug' => '81874', 'linie' => 'RB 71', 'ziel' => ['AWST', 'Wrist'], 'gleis' => '3'],
        ]),
    ];
}

/** Zuglauf des RB 71 81871 Wrist – Hamburg Hbf, alle Halte bedient. */
function t_lauf_81871(array $opt = [])
{
    $o = $opt + ['AP' => [], 'ADF' => [], 'AH' => [], 'zuglauf' => '', 'ursache' => ''];

    return t_zug('81871', 'RB 71', $o['ursache'], [
        ['AWST', 'Wrist', '', '09:53'],
        ['AEL', 'Elmshorn', '10:07', '10:08'],
        ['ATM', 'Tornesch', '10:13', '10:14'],
        ['APD', 'Prisdorf', '10:17', '10:18'],
        ['AP', 'Pinneberg', '10:21', '10:22', $o['AP']],
        ['ADF', 'Hamburg Dammtor', '10:37', '10:38', $o['ADF']],
        ['AH', 'Hamburg Hbf', '10:41', '', $o['AH']],
    ], $o['zuglauf']);
}

/** Zuglauf des RB 61 75514 Hamburg Hbf – Itzehoe. */
function t_lauf_75514(array $opt = [])
{
    $o = $opt + ['AH' => [], 'ADF' => [], 'AP' => [], 'ursache' => '', 'zuglauf' => '', 'extra' => []];
    $halte = [
        ['AH', 'Hamburg Hbf', '', '10:56', $o['AH']],
        ['ADF', 'Hamburg Dammtor', '10:59', '11:00', $o['ADF']],
    ];
    foreach ($o['extra'] as $h) {
        $halte[] = $h;
    }
    $halte[] = ['AP', 'Pinneberg', '11:15', '11:16', $o['AP']];
    $halte[] = ['APD', 'Prisdorf', '11:19', '11:19'];
    $halte[] = ['AEL', 'Elmshorn', '11:28', '11:29'];
    $halte[] = ['AIZ', 'Itzehoe', '11:53', ''];

    return t_zug('75514', 'RB 61', $o['ursache'], $halte, $o['zuglauf']);
}
