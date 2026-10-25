<?php
/**
 * Live-Karte (api/karte.php): Kandidaten von den Tafeln, Laufweg lesen, Lage
 * zwischen den Halten rechnen, Streckennetz. Die Uhr steht auf 10:00, wenn
 * nichts anderes gesagt wird; der RB 71 81871 (Wrist 09:53 – Hamburg Hbf 10:41)
 * ist dann zwischen Wrist und Elmshorn.
 */

/** Tafeln aller Bahnhoefe der Karte, leer bis auf $tafeln (ID => Zeilen), dazu Laufwege. */
function t_karte_quellen(array $tafeln = [], array $zuege = [])
{
    $q = [];
    foreach (KA_STATIONEN as $id) {
        $q[$id] = t_tafel($id, $tafeln[$id] ?? []);
    }
    foreach ($zuege as $nr => $xml) {
        $q['zug:' . $nr] = $xml;
    }

    return $q;
}

/** Standardtag: der 81871 steht auf den Tafeln seiner naechsten Halte, der 75514 am Hbf, ein RB 60 in Altona. */
function t_karte_standard(array $zuege = [], array $tafeln = [])
{
    $z81871 = ['zug' => '81871', 'linie' => 'RB 71', 'ziel' => ['AH', 'Hamburg Hbf']];
    $z75514 = ['zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '13'];

    return t_karte_quellen($tafeln + [
        'AEL' => [['zeit' => '10:08'] + $z81871],
        'ATM' => [['zeit' => '10:14'] + $z81871],
        'APD' => [['zeit' => '10:18'] + $z81871],
        'AP'  => [['zeit' => '10:22'] + $z81871],
        'ADF' => [['zeit' => '10:38'] + $z81871, ['zeit' => '10:59'] + $z75514],
        'AH'  => [['zeit' => '10:56'] + $z75514],
        'AA'  => [['zeit' => '10:15', 'zug' => '28542', 'linie' => 'RB 60', 'ziel' => ['AEL', 'Elmshorn'], 'gleis' => '7']],
    ], $zuege + [
        '81871' => t_lauf_81871(),
        '75514' => t_lauf_75514(['AH' => ['gleis' => '13']]),
        '28542' => t_zug('28542', 'RB 60', '', [
            ['AA', 'Hamburg-Altona', '', '10:15', ['gleis' => '7']],
            ['AP', 'Pinneberg', '10:31', '10:32'],
            ['AEL', 'Elmshorn', '10:46', ''],
        ]),
    ]);
}

function t_karte(array $quellen, $zeit = T_NOW, ?array $alt = null, ?array &$calls = null)
{
    return ka_build(t_fetch($quellen, $calls), t_now($zeit), $alt);
}

function t_karte_zug($result, $zug)
{
    foreach ($result['zuege'] ?? [] as $z) {
        if ($z['zug'] === (string) $zug) {
            return $z;
        }
    }
    throw new TestFailure("Zug $zug fehlt auf der Karte: " . json_encode(array_column($result['zuege'] ?? [], 'zug')));
}

function t_karte_zuege($result)
{
    return array_column($result['zuege'] ?? [], 'zug');
}

// --- Lage ------------------------------------------------------------------

function test_karte_zug_unterwegs_zwischen_zwei_halten()
{
    $r = t_karte(t_karte_standard(), T_NOW, null, $calls);

    t_eq(true, $r['ok'], 'Antwort ok');
    t_eq(15, count($r['quellen']), 'alle Tafeln abgefragt');
    t_eq(['28542', '81871'], t_karte_zuege($r), 'der Zug unterwegs und der gleich startende, nach Linie sortiert');
    $z = t_karte_zug($r, '81871');
    t_eq('RB71', $z['linie'], 'Linie ohne Leerzeichen');
    t_eq('Wrist', $z['von'], 'Startbahnhof');
    t_eq('Hamburg Hbf', $z['nach'], 'Zielbahnhof');
    t_eq('hamburg', $z['richtung'], 'faehrt Richtung Hamburg');
    t_eq('unterwegs', $z['status'], 'zwischen zwei Halten');
    t_eq('AWST', $z['lage']['von']['id'], 'kommt von Wrist');
    t_eq('AEL', $z['lage']['nach']['id'], 'faehrt nach Elmshorn');
    t_eq('10:07', t_uhr($z['lage']['nach']['an']), 'Ankunft in Elmshorn');
    t_eq(0.5, $z['lage']['anteil'], 'Abfahrt 09:53, Ankunft 10:07: um 10:00 die Haelfte');
    t_eq(null, $z['lage']['bei'], 'kein Halt');
    t_assert($z['lage']['lat'] > 53.754 && $z['lage']['lat'] < 53.932, 'Breite zwischen Elmshorn und Wrist: ' . $z['lage']['lat']);
    t_assert($z['lage']['lon'] > 9.659 && $z['lage']['lon'] < 9.749, 'Laenge zwischen Elmshorn und Wrist: ' . $z['lage']['lon']);
    t_eq('Zwischen Wrist und Elmshorn, Elmshorn 10:07', $z['lage']['text'], 'Lagetext');
    t_eq(0, $z['verspaetung'], 'puenktlich');
    t_eq('', $z['hinweis'], 'kein Hinweis');
    t_eq(1, $z['wagen'], 'Wagenzahl von der Tafel');
    t_eq(7, count($z['halte']), 'alle Halte dabei');
    t_eq('10:41', t_uhr($z['ende']), 'Ende ist die Ankunft am Hbf');
    t_eq('10:08', t_uhr($z['halte'][1]['ab_ist']), 'Ist-Abfahrt in Elmshorn');

    // Der 75514 faehrt erst 10:56 ab Hbf, also nicht abgefragt und nicht auf der Karte
    $abgefragt = array_values(array_filter($calls, function ($u) { return strpos($u, 'zug=') !== false; }));
    t_eq(2, count($abgefragt), 'nur zwei Laufwege geholt');
    t_eq(false, in_array(abf_zug_url('75514'), $abgefragt, true), 'Zug in 56 Minuten nicht abgefragt');
}

function test_karte_zug_haelt()
{
    $z = t_karte_zug(t_karte(t_karte_standard(), '2026-10-15 10:07:30'), '81871');
    t_eq('halt', $z['status'], 'zwischen Ankunft 10:07 und Abfahrt 10:08');
    t_eq('AEL', $z['lage']['bei']['id'], 'in Elmshorn');
    t_eq([53.75403, 9.65941], [$z['lage']['lat'], $z['lage']['lon']], 'Koordinate von Elmshorn');
    t_eq('Hält in Elmshorn, weiter 10:08', $z['lage']['text'], 'Lagetext');
    t_eq(null, $z['lage']['anteil'], 'kein Anteil am Halt');
}

function test_karte_wartet_vor_der_abfahrt()
{
    $r = t_karte(t_karte_standard(), '2026-10-15 10:40:00');
    $z = t_karte_zug($r, '75514');
    t_eq('wartet', $z['status'], '16 Minuten vor der Abfahrt');
    t_eq('AH', $z['lage']['bei']['id'], 'am Hbf');
    t_eq('Steht in Hamburg Hbf, Abfahrt 10:56, Gleis 13', $z['lage']['text'], 'Lagetext mit Gleis');
    t_eq('nord', $z['richtung'], 'faehrt nach Norden');
    t_eq('Itzehoe', $z['nach'], 'Ziel');

    $z = t_karte_zug($r, '28542');
    t_eq('unterwegs', $z['status'], 'der RB 60 ist um 10:40 zwischen Pinneberg und Elmshorn');
    t_eq('AP', $z['lage']['von']['id'], 'von Pinneberg');
    t_eq('AEL', $z['lage']['nach']['id'], 'nach Elmshorn');
    t_eq('RB60', $z['linie'], 'RB60');

    // 81871 ist um 10:40 angekommen (10:41 ist die Ankunft): noch unterwegs kurz vor dem Hbf
    $z = t_karte_zug($r, '81871');
    t_eq('unterwegs', $z['status'], 'kurz vor dem Hbf');
    t_eq('AH', $z['lage']['nach']['id'], 'nach Hbf');
}

function test_karte_angekommen_und_dann_weg()
{
    $z = t_karte_zug(t_karte(t_karte_standard(), '2026-10-15 10:42:00'), '81871');
    t_eq('ziel', $z['status'], 'eine Minute nach der Ankunft');
    t_eq('Angekommen in Hamburg Hbf um 10:41', $z['lage']['text'], 'Lagetext');
    t_eq([53.55274, 10.00691], [$z['lage']['lat'], $z['lage']['lon']], 'Koordinate des Hbf');

    $r = t_karte(t_karte_standard(), '2026-10-15 10:45:00');
    t_eq(false, in_array('81871', t_karte_zuege($r), true), 'vier Minuten nach der Ankunft weg');
}

function test_karte_verspaetung_verschiebt_die_lage()
{
    // Elmshorn sechs Minuten spaeter: Ankunft 10:13 statt 10:07
    $lauf = t_zug('81871', 'RB 71', 'Verspätung aus vorheriger Fahrt', [
        ['AWST', 'Wrist', '', '09:53'],
        ['AEL', 'Elmshorn', '10:07', '10:08', ['an_min' => 6, 'ab_min' => 6]],
        ['ATM', 'Tornesch', '10:13', '10:14', ['an_min' => 5, 'ab_min' => 5]],
        ['APD', 'Prisdorf', '10:17', '10:18', ['an_min' => 5, 'ab_min' => 5]],
        ['AP', 'Pinneberg', '10:21', '10:22', ['an_min' => 4, 'ab_min' => 4]],
        ['ADF', 'Hamburg Dammtor', '10:37', '10:38', ['an_min' => 3, 'ab_min' => 3]],
        ['AH', 'Hamburg Hbf', '10:41', '', ['an_min' => 3]],
    ]);
    $z = t_karte_zug(t_karte(t_karte_standard(['81871' => $lauf]), '2026-10-15 10:10:00'), '81871');
    t_eq('unterwegs', $z['status'], 'um 10:10 noch vor Elmshorn');
    t_eq(6, $z['verspaetung'], 'Verspaetung am naechsten Halt');
    t_eq('10:13', t_uhr($z['lage']['nach']['an']), 'Ist-Ankunft');
    t_eq(0.85, $z['lage']['anteil'], '17 von 20 Minuten');
    t_eq('Verspätung aus vorheriger Fahrt', $z['hinweis'], 'Ursache');
    t_eq('Zwischen Wrist und Elmshorn, Elmshorn 10:13', $z['lage']['text'], 'Lagetext mit Ist-Zeit');
    t_eq(6, $z['halte'][1]['verspaetung'], 'Verspaetung je Halt');
    t_eq('10:13', t_uhr($z['halte'][1]['an_ist']), 'Ist-Zeit je Halt');
    t_eq('10:07', t_uhr($z['halte'][1]['an']), 'Soll-Zeit je Halt');

    // Um 10:10 haelt er laut Ist noch nicht in Elmshorn; um 10:13:30 steht er dort
    // (mit der Abfahrtssekunde 10:14:00 gilt er als abgefahren)
    $z = t_karte_zug(t_karte(t_karte_standard(['81871' => $lauf]), '2026-10-15 10:13:30'), '81871');
    t_eq('halt', $z['status'], 'Ist-Ankunft 10:13, Ist-Abfahrt 10:14');
    t_eq('AEL', $z['lage']['bei']['id'], 'in Elmshorn');
}

function test_karte_entfallener_halt_wird_uebersprungen()
{
    $lauf = t_lauf_81871(['AP' => ['ausfall' => true], 'zuglauf' => 'Ausfall: hält heute nicht in Pinneberg.']);
    $z = t_karte_zug(t_karte(t_karte_standard(['81871' => $lauf]), '2026-10-15 10:30:00'), '81871');
    t_eq('unterwegs', $z['status'], 'unterwegs');
    t_eq('APD', $z['lage']['von']['id'], 'von Prisdorf');
    t_eq('ADF', $z['lage']['nach']['id'], 'direkt nach Dammtor, Pinneberg entfaellt');
    t_eq(round(12 / 19, 4), $z['lage']['anteil'], 'Abfahrt 10:18, Ankunft 10:37');
    t_eq(false, $z['halte'][4]['bedient'], 'Pinneberg nicht bedient');
    t_eq(true, $z['halte'][4]['ausfall'], 'Pinneberg entfaellt');
    t_eq('Hält heute nicht in Pinneberg', $z['hinweis'], 'Hinweis aus dem Zuglauftext');
    // Die Koordinate liegt auf dem Weg ueber Pinneberg (das Netz kennt keinen anderen)
    t_assert($z['lage']['lat'] < 53.6553 && $z['lage']['lat'] > 53.558, 'zwischen Pinneberg und Hamburg: ' . $z['lage']['lat']);
}

function test_karte_zug_endet_vorzeitig_in_pinneberg()
{
    // Nordbahn meldet "haelt nicht in Pinneberg, Dammtor, Hbf", der Zug kommt in Pinneberg
    // aber laut Prognose an und wendet dort (Ankunft "puenktlich", Abfahrt "Halt entfaellt!")
    $lauf = t_lauf_81871([
        'AP'  => ['ausfall' => true, 'an_prog' => 'pünktlich'],
        'ADF' => ['ausfall' => true],
        'AH'  => ['ausfall' => true],
        'zuglauf' => 'Ausfall: hält heute nicht in Pinneberg, Hamburg Dammtor, Hamburg Hbf.',
    ]);
    $z = t_karte_zug(t_karte(t_karte_standard(['81871' => $lauf]), '2026-10-15 10:19:00'), '81871');
    t_eq('Pinneberg', $z['nach'], 'endet in Pinneberg');
    t_eq('10:21', t_uhr($z['ende']), 'Ende ist die Ankunft in Pinneberg');
    t_eq('unterwegs', $z['status'], 'kurz vor Pinneberg');
    t_eq('AP', $z['lage']['nach']['id'], 'nach Pinneberg');

    $z = t_karte_zug(t_karte(t_karte_standard(['81871' => $lauf]), '2026-10-15 10:23:00'), '81871');
    t_eq('ziel', $z['status'], 'zwei Minuten nach der Ankunft in Pinneberg');
    t_eq('AP', $z['lage']['bei']['id'], 'in Pinneberg');

    $r = t_karte(t_karte_standard(['81871' => $lauf]), '2026-10-15 10:30:00');
    t_eq(false, in_array('81871', t_karte_zuege($r), true), 'danach weg, obwohl Dammtor und Hbf noch im Laufweg stehen');
}

// --- Kandidaten und voriger Stand --------------------------------------------

function test_karte_zug_ohne_tafel_bleibt_aus_dem_vorigen_stand()
{
    // 10:39: der 81871 hat Dammtor verlassen, der Hbf zeigt keine Ankuenfte. Ohne vorigen
    // Stand ist er unsichtbar, mit vorigem Stand wird er weiter abgefragt.
    $quellen = t_karte_quellen([], ['81871' => t_lauf_81871()]);
    $r = t_karte($quellen, '2026-10-15 10:39:00');
    t_eq([], t_karte_zuege($r), 'ohne vorigen Stand unbekannt');

    $alt = t_karte(t_karte_standard(), '2026-10-15 10:30:00');
    $r = t_karte($quellen, '2026-10-15 10:39:00', $alt, $calls);
    t_eq(['28542', '81871'], t_karte_zuege($r), 'beide Zuege des vorigen Stands weiter dabei (der RB 60 kommt erst 10:46 an)');
    $z = t_karte_zug($r, '81871');
    t_eq('unterwegs', $z['status'], 'zwischen Dammtor und Hbf');
    t_eq('ADF', $z['lage']['von']['id'], 'von Dammtor');
    t_eq(true, in_array(abf_zug_url('81871'), $calls, true), 'Laufweg frisch abgefragt');
    t_eq(true, in_array(abf_zug_url('28542'), $calls, true), 'auch der RB 60 wird frisch abgefragt');
    t_eq(false, in_array(abf_zug_url('75514'), $calls, true), 'der 75514 stand im vorigen Stand nicht drin (Abfahrt 10:56) und auf keiner Tafel: nicht abgefragt');
}

function test_karte_alter_zug_aus_vorigem_stand_wird_nicht_mehr_abgefragt()
{
    $alt = t_karte(t_karte_standard(), '2026-10-15 10:30:00');
    // Um 10:50 ist der 81871 (Ende 10:41) mehr als KA_NACHLAUF Minuten am Ziel
    $r = t_karte(t_karte_quellen(), '2026-10-15 10:50:00', $alt, $calls);
    t_eq([], t_karte_zuege($r), 'nichts mehr auf der Karte');
    t_eq([], array_values(array_filter($calls, function ($u) { return strpos($u, 'zug=') !== false; })), 'kein Laufweg abgefragt');
}

function test_karte_laufweg_nicht_erreichbar_alter_laufweg_gilt()
{
    $alt = t_karte(t_karte_standard(), '2026-10-15 10:00:00');
    $quellen = t_karte_standard();
    unset($quellen['zug:81871']);
    $z = t_karte_zug(t_karte($quellen, '2026-10-15 10:16:00', $alt), '81871');
    t_eq('unterwegs', $z['status'], 'mit dem alten Laufweg gerechnet');
    t_eq('ATM', $z['lage']['von']['id'], 'von Tornesch (ab 10:14)');
    t_eq('APD', $z['lage']['nach']['id'], 'nach Prisdorf (an 10:17)');
    t_eq('RB71', $z['linie'], 'Linie aus dem alten Stand');

    // Ohne alten Stand faellt der Zug weg
    $r = t_karte($quellen, '2026-10-15 10:16:00');
    t_eq(false, in_array('81871', t_karte_zuege($r), true), 'ohne Laufweg keine Position');
}

function test_karte_busse_fremde_linien_und_ausgefallene_zuege_fehlen()
{
    $quellen = t_karte_standard([
        '99001' => t_zug('99001', 'RB 71', 'Personalausfall', [
            ['AWST', 'Wrist', '', '09:55', ['ausfall' => true]],
            ['AEL', 'Elmshorn', '10:09', '10:10', ['ausfall' => true]],
            ['AH', 'Hamburg Hbf', '10:43', '', ['ausfall' => true]],
        ]),
        '99002' => t_zug('99002', 'RE 7', '', [['AEL', 'Elmshorn', '', '10:05'], ['AH', 'Hamburg Hbf', '10:40', '']]),
    ], [
        'AEL' => [
            ['zeit' => '10:08', 'zug' => '81871', 'linie' => 'RB 71'],
            ['zeit' => '10:10', 'zug' => '99001', 'linie' => 'RB 71', 'ausfall' => true],
            ['zeit' => '10:05', 'zug' => '99002', 'linie' => 'RE 7'],
            ['zeit' => '10:12', 'zug' => '99003', 'linie' => 'RB 71', 'sev' => true],
        ],
    ]);
    $r = t_karte($quellen, T_NOW, null, $calls);
    t_eq(['28542', '81871'], t_karte_zuege($r), 'nur die fahrenden RB');
    t_eq(false, in_array(abf_zug_url('99002'), $calls, true), 'RE nicht abgefragt');
    t_eq(false, in_array(abf_zug_url('99003'), $calls, true), 'Bus nicht abgefragt');
    t_eq(true, in_array(abf_zug_url('99001'), $calls, true), 'ausgefallener Zug abgefragt (koennte teilweise fahren)');
}

function test_karte_ohne_tafeln_kein_stand()
{
    $quellen = [];
    t_eq(null, t_karte($quellen), 'keine Tafel lesbar: null');
    $r = ka_cached(t_fetch($quellen), t_now());
    t_eq(false, $r['ok'], 'Cache antwortet mit ok=false');
    t_eq([], $r['zuege'], 'ohne Zuege');
}

function test_karte_cache_wird_benutzt()
{
    $quellen = t_karte_standard();
    $r = ka_cached(t_fetch($quellen, $calls), t_now());
    t_eq(true, $r['ok'], 'frisch gebaut');
    t_eq(['28542', '81871'], t_karte_zuege($r), 'zwei Zuege');
    $r2 = ka_cached(t_fetch($quellen, $calls2), t_now('2026-10-15 10:00:30'));
    t_eq([], $calls2, 'innerhalb von KA_TTL keine Abfrage');
    t_eq($r['fetched_at'], $r2['fetched_at'], 'derselbe Stand');
    t_eq(true, nordbahn_sofort_liefern($r, KA_TTL, KA_STALE_TTL, t_now('2026-10-15 10:02:00')->getTimestamp()), 'zwei Minuten alt: sofort liefern, im Hintergrund erneuern');
    t_eq(false, nordbahn_sofort_liefern($r, KA_TTL, KA_STALE_TTL, t_now('2026-10-15 10:04:00')->getTimestamp()), 'vier Minuten alt: neu bauen');
}

// --- Streckennetz -----------------------------------------------------------

function test_karte_streckennetz_pfade()
{
    $s = ka_strecke();
    foreach (KA_STATIONEN as $id) {
        t_assert(isset($s['stationen'][$id]['lat'], $s['stationen'][$id]['lon']), "Station $id hat Koordinaten");
    }
    $p = ka_pfad('AH', 'AP');
    t_assert(is_array($p) && count($p) > 4, 'Hbf – Pinneberg laeuft ueber Stuetzpunkte');
    t_eq([53.55274, 10.00691], $p[0], 'beginnt am Hbf');
    t_eq([53.65518, 9.79761], $p[count($p) - 1], 'endet in Pinneberg');
    t_eq(true, in_array([53.56075, 9.98957], $p, true), 'ueber den Dammtor');
    $r = ka_pfad('AP', 'AH');
    t_eq(array_reverse($p), $r, 'Rueckweg ist der umgekehrte Pfad');
    $a = ka_pfad('AA', 'AP');
    t_eq([53.55270, 9.93518], $a[0], 'Altona – Pinneberg beginnt in Altona');
    t_eq(false, in_array([53.56075, 9.98957], $a, true), 'Altona – Pinneberg geht nicht ueber den Dammtor');
    $b = ka_pfad('APD', 'AHOT');
    t_eq(true, in_array([53.75403, 9.65941], $b, true), 'Prisdorf – Horst laeuft ueber Elmshorn');
    t_eq(null, ka_pfad('AP', 'XX'), 'unbekannte Station: kein Pfad');

    $zwei = [[53.0, 9.0], [54.0, 9.0]];
    t_eq([53.0, 9.0], ka_punkt_auf_pfad($zwei, 0), 'Anteil 0 ist der Anfang');
    t_eq([54.0, 9.0], ka_punkt_auf_pfad($zwei, 1), 'Anteil 1 ist das Ende');
    t_eq([53.5, 9.0], ka_punkt_auf_pfad($zwei, 0.5), 'Anteil 0,5 ist die Mitte');
    t_eq([53.0, 9.0], ka_punkt_auf_pfad($zwei, -3), 'Anteil unter 0 wird begrenzt');
    $drei = [[53.0, 9.0], [53.0, 9.5], [54.0, 9.5]];
    $m = ka_punkt_auf_pfad($drei, 0.5);
    t_assert($m[1] === 9.5 && $m[0] > 53.0 && $m[0] < 54.0, 'laengenanteilig auf dem zweiten Stueck: ' . json_encode($m));
    t_assert(abs(ka_dist([53.0, 9.0], [54.0, 9.0]) - 111195) < 200, 'ein Breitengrad sind rund 111 km');
}
