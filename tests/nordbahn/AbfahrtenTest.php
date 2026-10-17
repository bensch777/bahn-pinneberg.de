<?php
/**
 * Abfahrten (api/abfahrten.php): Tafeln lesen, Richtungen bilden, Ersatz,
 * Dammtor, Teilausfaelle. Fixtures siehe tests/support.php; die Uhr steht auf
 * 10:00, Prisdorf ist Heimat, wenn nichts anderes gesagt wird.
 */

// --- Normalbetrieb ---------------------------------------------------------

function test_normale_abfahrten_beide_richtungen()
{
    $r = t_build(t_standard());

    t_eq(true, $r['ok'], 'Antwort ok');
    t_eq(['id' => 'APD', 'name' => 'Prisdorf'], $r['heimat'], 'Heimat');
    t_eq(['APD' => true, 'AH' => true, 'AA' => true, 'ADF' => true, 'AP' => true], $r['quellen'], 'alle Quellen gelesen, Pinneberg als Startbahnhof dabei');

    // Hin: nur Zuege nach Hamburg, der nach Wrist bleibt draussen
    t_eq(['81871', '75515'], t_zuege($r, 'hin'), 'Hinrichtung nach Zeit sortiert, nur Hamburg-Ziele');
    $z = t_zeile($r, 'hin', '81871');
    t_eq('10:18', t_uhr($z['zeit']), 'Planzeit');
    t_eq('10:18', t_uhr($z['ist']), 'Istzeit ohne Verspaetung');
    t_eq('Hamburg Hbf', $z['ziel'], 'Ziel');
    t_eq('2', $z['gleis'], 'Gleis');
    t_eq(null, $z['gleis_plan'], 'kein Gleiswechsel');
    t_eq(1, $z['wagen'], 'Wagenzahl');
    t_eq(false, $z['ausfall'], 'kein Ausfall');
    t_eq('', $z['hinweis'], 'kein Hinweis');
    t_eq(null, $z['teil'], 'kein Teilausfall');
    t_eq(2, t_zeile($r, 'hin', '75515')['wagen'], 'zwei Wagen');

    // Rueck: Altona und Hbf gemischt nach Zeit, Dammtor haengt an der Hbf-Zeile
    t_eq(['28542', '75514', '81874'], t_zuege($r, 'rueck'), 'Rueckrichtung nach Zeit sortiert');
    $a = t_zeile($r, 'rueck', '28542');
    t_eq('Altona', $a['ab'], 'Startbahnhof Altona');
    t_eq('Elmshorn', $a['ziel'], 'Ziel Elmshorn');
    t_eq('7', $a['gleis'], 'Gleis in Altona');
    $h = t_zeile($r, 'rueck', '75514');
    t_eq('Hbf', $h['ab'], 'Startbahnhof Hbf');
    t_eq('13', $h['gleis'], 'Gleis am Hbf');
    t_eq('10:59', t_uhr($h['dammtor']['zeit']), 'Dammtor-Halt haengt an der Hbf-Zeile');
    t_eq('3', $h['dammtor']['gleis'], 'Dammtor-Gleis');
    t_eq(false, $h['dammtor']['ausfall'], 'Dammtor-Halt findet statt');
    t_eq(null, $a['dammtor'], 'Altona-Zeile hat keinen Dammtor-Halt');
}

function test_verspaetung_mit_ursache()
{
    $r = t_build(t_standard([
        'APD' => t_tafel('Prisdorf', [['zeit' => '10:18', 'zug' => '81871', 'prognose' => '+ 7 Min', 'prognosemin' => 7]]),
        'zug:81871' => t_lauf_81871(['ursache' => 'Signalstörung']),
    ]));
    $z = t_zeile($r, 'hin', '81871');
    t_eq(7, $z['verspaetung'], 'Verspaetung in Minuten');
    t_eq('10:18', t_uhr($z['zeit']), 'Planzeit bleibt');
    t_eq('10:25', t_uhr($z['ist']), 'Istzeit = Plan + Verspaetung');
    t_eq('Signalstörung', $z['hinweis'], 'Ursache aus der Zugauskunft, der Zeitteil der Prognose nicht');
}

function test_gleiswechsel()
{
    $r = t_build(t_standard([
        'APD' => t_tafel('Prisdorf', [
            ['zeit' => '10:18', 'zug' => '81871', 'gleis' => '2', 'gleiswechsel' => '1', 'prognose' => 'pünktlich, heute Gleis 1'],
            ['zeit' => '10:40', 'zug' => '75515', 'gleis' => '2', 'gleiswechsel' => 'true'],
        ]),
    ]));
    $z = t_zeile($r, 'hin', '81871');
    t_eq('1', $z['gleis'], 'heutiges Gleis');
    t_eq('2', $z['gleis_plan'], 'Plangleis');
    t_eq('', $z['hinweis'], '"heute Gleis 1" ist kein Texthinweis, das zeigt das Gleisfeld');
    $z = t_zeile($r, 'hin', '75515');
    t_eq('2', $z['gleis'], 'ohne Nummer bleibt das Plangleis');
    t_eq(null, $z['gleis_plan'], 'ohne Nummer kein Plangleis');
    t_eq('Gleiswechsel', $z['hinweis'], 'ohne Nummer nur der Hinweis');
}

function test_ersatzverkehr_mit_bus_und_freitext()
{
    $r = t_build(t_standard([
        'APD' => t_tafel('Prisdorf', [['zeit' => '10:18', 'zug' => '81871', 'sev' => true, 'freitext' => 'Bus hält am ZOB']]),
    ]));
    t_eq('Schienenersatzverkehr mit Bus. Bus hält am ZOB', t_zeile($r, 'hin', '81871')['hinweis'], 'Bus und Freitext');
}

function test_vergangenes_verschwindet_ausfall_bleibt_fuenf_minuten()
{
    $r = t_build(t_standard([
        'APD' => t_tafel('Prisdorf', [
            ['zeit' => '09:58', 'zug' => '1'],
            ['zeit' => '09:59', 'zug' => '2'],
            ['zeit' => '09:54', 'zug' => '3', 'ausfall' => true],
            ['zeit' => '09:56', 'zug' => '4', 'ausfall' => true],
            ['zeit' => '10:18', 'zug' => '5'],
        ]),
    ]));
    t_eq(['4', '2', '5'], t_zuege($r, 'hin'), 'eine Minute Gnade fuer Abfahrten, fuenf fuer Ausfaelle; Ausfall sortiert nach Planzeit');
    t_eq(true, t_zeile($r, 'hin', '4')['ausfall'], 'Ausfall bleibt markiert');
    t_eq(null, t_zeile($r, 'hin', '4')['ist'], 'Ausfall hat keine Istzeit');
}

// --- Ersatz, Umleitung, Dammtor --------------------------------------------

function test_ausfall_am_hbf_mit_ersatz_ab_altona()
{
    $r = t_build(t_standard([
        'AH' => t_tafel('Hamburg Hbf', [['zeit' => '10:56', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '13', 'ausfall' => true]]),
        'AA' => t_tafel('Hamburg-Altona', [['zeit' => '11:04', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '7']]),
        'ADF' => t_tafel('Hamburg Dammtor', []),
        // Zuglauf: Hbf entfaellt, der Zug startet in Altona
        'zug:75514' => t_lauf_75514(['AH' => ['ausfall' => true], 'ADF' => ['ausfall' => true], 'ursache' => 'Oberleitungsschaden',
            'extra' => [['AA', 'Hamburg-Altona', '', '11:04']]]),
    ]));
    t_eq(['75514', '75514'], t_zuege($r, 'rueck'), 'Ausfall und Ersatz als eigene Zeilen');
    [$ausfall, $ersatz] = $r['richtungen']['rueck'];
    t_eq('Hbf', $ausfall['ab'], 'Ausfallzeile ab Hbf');
    t_eq(true, $ausfall['ausfall'], 'am Hbf ausgefallen');
    t_eq('Altona', $ausfall['ersatz']['ab'], 'Ersatz faehrt ab Altona');
    t_eq('11:04', t_uhr($ausfall['ersatz']['zeit']), 'Ersatzzeit');
    t_eq('7', $ausfall['ersatz']['gleis'], 'Ersatzgleis');
    t_eq(null, $ausfall['teil'], 'Start in Altona ist kein Teilausfall, sondern der Ersatz-Fall');
    t_contains('Oberleitungsschaden', $ausfall['hinweis'], 'Ursache am Ausfall');
    t_eq('Altona', $ersatz['ab'], 'Ersatzzeile ab Altona');
    t_eq(false, $ersatz['ausfall'], 'Ersatz faehrt');
    t_eq(['ab' => 'Hbf', 'zeit' => $ausfall['zeit']], $ersatz['ersatz_fuer'], 'Ersatzzeile verweist auf den Ausfall');
}

function test_umleitung_ueber_altona_in_der_hinrichtung()
{
    $r = t_build(t_standard([
        'APD' => t_tafel('Prisdorf', [['zeit' => '10:18', 'zug' => '81871', 'ziel' => ['AA', 'Hamburg-Altona'], 'zuglaufinfo' => true, 'freitext' => 'Umleitung nach Hamburg-Altona']]),
        'zug:81871' => t_zug('81871', 'RB 71', 'Defektes Stellwerk', [
            ['APD', 'Prisdorf', '10:17', '10:18'],
            ['AP', 'Pinneberg', '10:21', '10:22'],
            ['AA', 'Hamburg-Altona', '10:40', ''],
        ]),
    ]));
    $z = t_zeile($r, 'hin', '81871');
    t_eq('Hamburg-Altona', $z['ziel'], 'Ziel ist Altona');
    t_eq(null, $z['teil'], 'Umleitung ist kein Teilausfall');
    t_eq(false, $z['ausfall'], 'faehrt');
    t_contains('Umleitung nach Hamburg-Altona', $z['hinweis'], 'Freitext als Hinweis');
    t_contains('Defektes Stellwerk', $z['hinweis'], 'Ursache dazu');
    t_contains('Umleitung über Altona', nb_status_betrieb($r)['detail'], 'Fahrplankarte zaehlt die Umleitung');
}

function test_dammtor_als_eigene_zeile_wenn_hbf_schon_weg()
{
    $r = t_build(t_standard([
        'AH' => t_tafel('Hamburg Hbf', [['zeit' => '11:17', 'zug' => '81874', 'linie' => 'RB 71', 'ziel' => ['AWST', 'Wrist'], 'gleis' => '13']]),
    ]));
    t_eq(['28542', '75514', '81874'], t_zuege($r, 'rueck'), 'der am Hbf schon abgefahrene 75514 steht ab Dammtor');
    $d = t_zeile($r, 'rueck', '75514');
    t_eq('Dammtor', $d['ab'], 'Startbahnhof Dammtor');
    t_eq('10:59', t_uhr($d['zeit']), 'Dammtor-Zeit');
    t_eq(null, $d['dammtor'], 'eigene Dammtor-Zeile ohne angehaengten Halt');
}

function test_dammtor_halt_entfaellt()
{
    $r = t_build(t_standard([
        'ADF' => t_tafel('Hamburg Dammtor', [['zeit' => '10:59', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '3', 'ausfall' => true]]),
    ]));
    t_eq(['28542', '75514', '81874'], t_zuege($r, 'rueck'), 'ausgefallener Dammtor-Halt wird keine eigene Zeile');
    t_eq(true, t_zeile($r, 'rueck', '75514')['dammtor']['ausfall'], 'Dammtor-Halt als entfallen markiert');
}

// --- Teilausfaelle ---------------------------------------------------------

function test_teilausfall_hin_hamburger_halte_entfallen()
{
    $r = t_build(t_standard([
        // Prisdorf meldet den Zug als ganz normale Abfahrt, nur der Zuglauf weiss mehr
        'zug:81871' => t_lauf_81871(['ADF' => ['ausfall' => true], 'AH' => ['ausfall' => true], 'ursache' => 'Defektes Stellwerk']),
    ]), 'APD', null, $calls);
    $z = t_zeile($r, 'hin', '81871');
    t_eq(['art' => 'endet', 'id' => 'AP', 'name' => 'Pinneberg', 'zeit' => '10:21'], $z['teil'], 'endet in Pinneberg');
    t_eq(false, $z['ausfall'], 'bis Pinneberg faehrt er');
    t_eq('Defektes Stellwerk', $z['hinweis'], 'Ursache');
    t_assert(count(preg_grep('/zug=81871/', $calls)) === 1, 'Zugauskunft auch fuer unauffaellige Zeilen geholt');
    t_contains('nur ab/bis Pinneberg', nb_status_betrieb($r)['detail'], 'Fahrplankarte zaehlt den Teilausfall');
}

/** Der echte Fall vom 15.10.2026: Nordbahn sagt "hält nicht in Pinneberg", der Zug wendet aber dort. */
function test_teilausfall_hin_wendehalt_pinneberg()
{
    $r = t_build(t_standard([
        'zug:81871' => t_lauf_81871([
            'AP' => ['ausfall' => true, 'an_prog' => 'pünktlich', 'ab_prog' => 'Halt entfällt!', 'gleis' => '5'],
            'ADF' => ['ausfall' => true],
            'AH' => ['ausfall' => false, 'an_prog' => 'pünktlich'], // Endhalt traegt das Flag nie
            'ursache' => 'Defektes Stellwerk',
            'zuglauf' => 'Ausfall: hält heute nicht in Pinneberg, Hamburg Dammtor, Hamburg Hbf',
        ]),
    ]));
    $z = t_zeile($r, 'hin', '81871');
    t_eq(['art' => 'endet', 'id' => 'AP', 'name' => 'Pinneberg', 'zeit' => '10:21'], $z['teil'], 'Wendehalt Pinneberg ist der Endhalt');
    t_eq(false, $z['ausfall'], 'kein Ausfall');
    t_eq('Defektes Stellwerk', $z['hinweis'], 'nur die Ursache als Hinweis');
}

function test_hin_zuege_mit_ziel_unterwegs_richtung_hamburg()
{
    // Wendet der Zug laut Tafel schon in Pinneberg ("RB71 Pinneberg"), ist er ab Prisdorf
    // trotzdem der Zug Richtung Hamburg: er bleibt in der Liste, als "nur bis Pinneberg".
    $r = t_build(t_standard([
        'APD' => t_tafel('Prisdorf', [
            ['zeit' => '10:30', 'zug' => '99901', 'linie' => 'RB 71', 'ziel' => ['AP', 'Pinneberg'], 'gleis' => '1'],
            ['zeit' => '10:35', 'zug' => '99902', 'linie' => 'RB 61', 'ziel' => ['AEL', 'Elmshorn'], 'gleis' => '2'],
            ['zeit' => '10:40', 'zug' => '99903', 'linie' => 'RB 61', 'ziel' => ['AH', 'Hamburg Hbf'], 'gleis' => '1'],
            ['zeit' => '10:45', 'zug' => '99904', 'linie' => 'RB 61', 'ziel' => ['APD', 'Prisdorf'], 'gleis' => '1'],
        ]),
    ]));
    t_eq(['99901', '99903'], t_zuege($r, 'hin'), 'Pinneberg-Zug bleibt, Elmshorn (Gegenrichtung) und Prisdorf (endet hier) nicht');
    t_eq(['art' => 'endet', 'id' => 'AP', 'name' => 'Pinneberg', 'zeit' => '', 'plan' => true], t_zeile($r, 'hin', '99901')['teil'], 'nur bis Pinneberg aus der Tafel, planmaessig');
    t_eq(null, t_zeile($r, 'hin', '99903')['teil'], 'Hamburg-Zug ohne Teilausfall');

    t_eq(true, abf_richtung_hamburg('AP', 'Pinneberg', 'AEL'), 'Elmshorn: Pinneberg liegt Richtung Hamburg');
    t_eq(true, abf_richtung_hamburg('AEL', 'Elmshorn', 'AIZ'), 'Itzehoe: Elmshorn liegt Richtung Hamburg');
    t_eq(false, abf_richtung_hamburg('AKM', 'Krempe', 'AHOT'), 'Horst: Krempe liegt auf dem anderen Ast');
    t_eq(false, abf_richtung_hamburg('ATM', 'Tornesch', 'AP'), 'Pinneberg: Tornesch liegt nach Norden');
    t_eq(true, abf_richtung_hamburg('AA', 'Hamburg-Altona', 'APD'), 'Altona ist Hamburg');
    t_eq(false, abf_richtung_hamburg('XYZ', 'Unbekannt', 'APD'), 'unbekanntes Ziel nicht');
}

function test_prisdorf_nachts_echte_tafel()
{
    // Echte Tafel vom 17.10.2026, 23:16 Uhr: Zuege enden planmaessig in Pinneberg, Busse als
    // Ersatzverkehr, und der Monitor traegt bei 00:08 und 00:17 das Datum von gestern.
    $xml = file_get_contents(__DIR__ . '/../fixtures-prisdorf-nacht.xml');
    $now = new DateTime('2026-10-17 23:16:00', new DateTimeZone('Europe/Berlin'));
    $r = t_build(t_standard(['APD' => $xml]), 'APD', $now);
    t_eq(['81895', '35539', '81897'], t_zuege($r, 'hin'), 'Pinneberg-Zug, Bus 00:08 und RB71 00:17 nach Hamburg; Itzehoe und Elmshorn nicht');
    $bus = t_zeile($r, 'hin', '35539');
    t_eq('2026-10-18T00:08:54+02:00', $bus['zeit'], 'falsches Datum des Monitors korrigiert');
    t_eq('Schienenersatzverkehr mit Bus', $bus['hinweis'], 'Bus-Hinweis statt der Prognosefloskel');
    t_eq(true, $bus['bus'], 'Bus-Merkmal gesetzt');
    t_eq(false, t_zeile($r, 'hin', '81897')['bus'], 'Zug ohne Bus-Merkmal');
    t_eq(true, t_zeile($r, 'hin', '81895')['teil']['plan'], 'planmaessig nur bis Pinneberg');
    t_eq('2026-10-18T00:17:42+02:00', t_zeile($r, 'hin', '81897')['zeit'], 'RB71 00:17 am naechsten Tag');

    // Statuskarte: planmaessige Kurzlaeufe sind keine Stoerung
    $st = nb_status_betrieb(['ok' => true, 'richtungen' => $r['richtungen']]);
    t_eq('ok', $st['level'], 'Fahrplan laeuft: ' . $st['title']);
}

function test_zeit_datum_vom_monitor_ueber_mitternacht()
{
    $now = new DateTime('2026-10-17 23:16:00', new DateTimeZone('Europe/Berlin'));
    t_eq('2026-10-18T00:08:54+02:00', abf_time('2026-10-17T00:08Z', '00:08:54', $now)->format('c'), 'gestern datiert, gemeint ist morgen');
    t_eq('2026-10-17T23:19:48+02:00', abf_time('2026-10-17T23:19Z', '23:19:48', $now)->format('c'), 'heute bleibt heute');
    t_eq('2026-10-18T00:07:12+02:00', abf_time('2026-10-18T00:07Z', '00:07:12', $now)->format('c'), 'morgen bleibt morgen');
    $frueh = new DateTime('2026-10-18 00:30:00', new DateTimeZone('Europe/Berlin'));
    t_eq('2026-10-17T23:50:00+02:00', abf_time('2026-10-18T23:50Z', '23:50:00', $frueh)->format('c'), 'kurz nach Mitternacht: 23:50 mit heutigem Datum war gestern');
}

function test_rueck_zuege_ab_pinneberg()
{
    // Nachts beginnen Zuege regulaer in Pinneberg: sie stehen auf keiner Hamburger Tafel,
    // kommen aber von der Pinneberger Tafel als "ab Pinneberg" in die Rueckrichtung.
    // Durchfahrer (laut Zuglauf ab Hamburg) und unbestaetigte Kandidaten bleiben draussen.
    $r = t_build(t_standard([
        'AP' => t_tafel('Pinneberg', [
            ['zeit' => '10:30', 'zug' => '90001', 'linie' => 'RB 71', 'ziel' => ['AWST', 'Wrist'], 'gleis' => '2'],   // startet hier
            ['zeit' => '10:33', 'zug' => '90002', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '1'], // Durchfahrer aus Hamburg
            ['zeit' => '10:36', 'zug' => '90003', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '1'], // ohne Zugauskunft
            ['zeit' => '10:40', 'zug' => '90004', 'linie' => 'RB 61', 'ziel' => ['AH', 'Hamburg Hbf'], 'gleis' => '3'], // Gegenrichtung
            ['zeit' => '11:15', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '1'], // steht schon auf der Hbf-Tafel
        ]),
        'zug:90001' => t_zug('90001', 'RB 71', '', [['AP', 'Pinneberg', '', '10:30'], ['APD', 'Prisdorf', '10:33', '10:34'], ['AEL', 'Elmshorn', '10:43', '10:44'], ['AWST', 'Wrist', '11:10', '']]),
        'zug:90002' => t_zug('90002', 'RB 61', '', [['AH', 'Hamburg Hbf', '', '10:08'], ['AP', 'Pinneberg', '10:32', '10:33'], ['APD', 'Prisdorf', '10:36', '10:37'], ['AIZ', 'Itzehoe', '11:20', '']]),
    ]));
    $zuege = t_zuege($r, 'rueck');
    t_eq(true, in_array('90001', $zuege, true), 'Startzug ab Pinneberg ist dabei');
    t_eq(false, in_array('90002', $zuege, true), 'Durchfahrer aus Hamburg nicht');
    t_eq(false, in_array('90003', $zuege, true), 'ohne Zugauskunft nicht');
    t_eq(false, in_array('90004', $zuege, true), 'Gegenrichtung nicht');
    t_eq(1, count(array_keys($zuege, '75514', true)), 'Hbf-Zug nur einmal, nicht nochmal ab Pinneberg');
    $z = t_zeile($r, 'rueck', '90001');
    t_eq(['Pinneberg', 'AP'], [$z['ab'], $r['richtungen']['rueck'][array_search('90001', $zuege, true)]['ab'] === 'Pinneberg' ? 'AP' : ''], 'ab Pinneberg');
    t_eq(['art' => 'beginnt', 'id' => 'AP', 'name' => 'Pinneberg', 'zeit' => '10:30', 'gleis' => '2', 'plan' => true], $z['teil'], 'planmaessiger Beginn in Pinneberg');
    t_eq('2', $z['gleis'], 'Gleis in Pinneberg');
    t_eq('ok', nb_status_betrieb(['ok' => true, 'richtungen' => $r['richtungen']])['level'], 'kein Stoerungszaehler');

    t_eq(['AP' => 'Pinneberg'], abf_startbahnhoefe('APD'), 'Prisdorf: nur Pinneberg');
    t_eq(['AP' => 'Pinneberg', 'AEL' => 'Elmshorn'], abf_startbahnhoefe('AIZ'), 'Itzehoe: Pinneberg und Elmshorn');
    t_eq([], abf_startbahnhoefe('AP'), 'Pinneberg selbst: keine');
}

function test_teilausfall_hin_heimat_pinneberg_ist_ausfall()
{
    $r = t_build(t_standard([
        'AP' => t_tafel('Pinneberg', [['zeit' => '10:22', 'zug' => '81871']]),
        'zug:81871' => t_lauf_81871([
            'AP' => ['ausfall' => true, 'an_prog' => 'pünktlich', 'ab_prog' => 'Halt entfällt!'],
            'ADF' => ['ausfall' => true],
            'AH' => ['ausfall' => false, 'an_prog' => 'pünktlich'],
            'ursache' => 'Defektes Stellwerk',
            'zuglauf' => 'Ausfall: hält heute nicht in Pinneberg, Hamburg Dammtor, Hamburg Hbf',
        ]),
    ]), 'AP');
    t_eq(['id' => 'AP', 'name' => 'Pinneberg'], $r['heimat'], 'Heimat Pinneberg');
    $z = t_zeile($r, 'hin', '81871');
    t_eq(true, $z['ausfall'], 'ab Pinneberg faehrt nichts mehr: Ausfall');
    t_eq(null, $z['teil'], 'kein Teilausfall');
    t_eq('Hält heute nicht in Pinneberg, Hamburg Dammtor, Hamburg Hbf. Defektes Stellwerk', $z['hinweis'], 'Nordbahn-Text und Ursache');
}

function test_teilausfall_hin_endet_am_heimatbahnhof_ohne_text()
{
    $r = t_build(t_standard([
        'zug:81871' => t_lauf_81871(['AP' => ['ausfall' => true], 'ADF' => ['ausfall' => true], 'AH' => ['ausfall' => true], 'ursache' => 'Defektes Stellwerk']),
    ]));
    $z = t_zeile($r, 'hin', '81871');
    t_eq(true, $z['ausfall'], 'Ausfall');
    t_eq('Endet heute schon in Prisdorf. Defektes Stellwerk', $z['hinweis'], 'Hinweis ohne Nordbahn-Text');
    t_contains('Zugausfall', nb_status_betrieb($r)['detail'], 'Fahrplankarte zaehlt den Ausfall');
}

function test_teilausfall_hin_wendet_erst_in_hamburg_ist_keiner()
{
    // Dammtor entfaellt, der Zug kommt am Hbf an: kein Teilausfall, nur ein Zwischenhalt fehlt
    $r = t_build(t_standard([
        'zug:81871' => t_lauf_81871(['ADF' => ['ausfall' => true], 'ursache' => 'Bauarbeiten']),
    ]));
    t_eq(null, t_zeile($r, 'hin', '81871')['teil'], 'erreicht den Hbf');
}

function test_teilausfall_rueck_beginnt_in_pinneberg()
{
    $now = t_now('2026-10-15 11:05:00'); // Hbf-Zeit 10:56 ist neun Minuten her
    $r = t_build(t_standard([
        'AH' => t_tafel('Hamburg Hbf', [
            ['zeit' => '10:56', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '13', 'ausfall' => true],
            ['zeit' => '11:10', 'zug' => '81874', 'linie' => 'RB 71', 'ziel' => ['AWST', 'Wrist'], 'gleis' => '13'],
        ], $now),
        'AA' => t_tafel('Hamburg-Altona', [], $now),
        'ADF' => t_tafel('Hamburg Dammtor', [['zeit' => '10:59', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '3', 'ausfall' => true]], $now),
        'zug:75514' => t_lauf_75514(['AH' => ['ausfall' => true], 'ADF' => ['ausfall' => true], 'AP' => ['gleis' => '5'], 'ursache' => 'Defektes Stellwerk']),
    ]), 'APD', $now);
    t_eq(['81874', '75514'], t_zuege($r, 'rueck'), 'bleibt stehen und sortiert sich nach der Pinneberg-Abfahrt ein');
    $z = t_zeile($r, 'rueck', '75514');
    t_eq(false, $z['ausfall'], 'kein Ausfall, sondern Abfahrt in Pinneberg');
    t_eq('11:16', t_uhr($z['zeit']), 'Abfahrt in Pinneberg');
    t_eq('10:56', t_uhr($z['zeit_plan']), 'Hamburger Planzeit bleibt als Hinweis');
    t_eq(null, $z['ist'], 'keine Istzeit');
    t_eq(0, $z['verspaetung'], 'keine Verspaetung');
    t_eq('5', $z['gleis'], 'Gleis in Pinneberg, nicht am Hbf');
    t_eq(null, $z['gleis_plan'], 'kein Gleiswechsel');
    t_eq(['art' => 'beginnt', 'id' => 'AP', 'name' => 'Pinneberg', 'zeit' => '11:16'], $z['teil'], 'beginnt in Pinneberg');
    t_eq(null, $z['ersatz'], 'kein Ersatz');
    t_eq('Defektes Stellwerk', $z['hinweis'], 'Ursache');
    t_contains('nur ab/bis Pinneberg', nb_status_betrieb($r)['detail'], 'Fahrplankarte zaehlt den Teilausfall');
}

function test_teilausfall_rueck_ohne_gleis_im_zuglauf()
{
    $r = t_build(t_standard([
        'AH' => t_tafel('Hamburg Hbf', [['zeit' => '10:56', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'gleis' => '13', 'ausfall' => true]]),
        'zug:75514' => t_lauf_75514(['AH' => ['ausfall' => true], 'ADF' => ['ausfall' => true]]),
    ]));
    t_eq(null, t_zeile($r, 'rueck', '75514')['gleis'], 'lieber kein Gleis als das falsche vom Hbf');
}

function test_teilausfall_rueck_alter_ausfall_verschwindet_trotzdem()
{
    // Ein Ausfall ohne Start unterwegs bleibt nur fuenf Minuten stehen, auch
    // wenn der Vorfilter laenger wartet
    $now = t_now('2026-10-15 11:05:00');
    $r = t_build(t_standard([
        'AH' => t_tafel('Hamburg Hbf', [['zeit' => '10:56', 'zug' => '75514', 'linie' => 'RB 61', 'ziel' => ['AIZ', 'Itzehoe'], 'ausfall' => true]], $now),
        'AA' => t_tafel('Hamburg-Altona', [], $now),
        'ADF' => t_tafel('Hamburg Dammtor', [], $now),
        'zug:75514' => t_lauf_75514(['AH' => ['ausfall' => true], 'ADF' => ['ausfall' => true], 'AP' => ['ausfall' => true]]),
    ]), 'APD', $now);
    t_eq([], t_zuege($r, 'rueck'), 'neun Minuten alter Komplettausfall ist weg');
}

// --- Heimatbahnhof ---------------------------------------------------------

function test_heimat_filtert_rueckrichtung_nach_ast()
{
    $hbf = t_tafel('Hamburg Hbf', [
        ['zeit' => '10:56', 'zug' => '1', 'ziel' => ['AIZ', 'Itzehoe']],
        ['zeit' => '11:00', 'zug' => '2', 'ziel' => ['AWST', 'Wrist']],
        ['zeit' => '11:05', 'zug' => '3', 'ziel' => ['AEL', 'Elmshorn']],
        ['zeit' => '11:10', 'zug' => '4', 'ziel' => ['AP', 'Pinneberg']],
    ]);
    $leer = t_tafel('x', []);
    $q = ['APD' => $leer, 'AP' => $leer, 'ATM' => $leer, 'AIZ' => $leer, 'AWST' => $leer, 'AH' => $hbf, 'AA' => $leer, 'ADF' => $leer];

    t_eq(['1', '2', '3', '4'], t_zuege(t_build($q, 'AP'), 'rueck'), 'Pinneberg: alles');
    t_eq(['1', '2', '3'], t_zuege(t_build($q, 'ATM'), 'rueck'), 'Tornesch: der Zug nur bis Pinneberg nicht');
    t_eq(['1'], t_zuege(t_build($q, 'AIZ'), 'rueck'), 'Itzehoe: nur der Itzehoe-Ast');
    t_eq(['2'], t_zuege(t_build($q, 'AWST'), 'rueck'), 'Wrist: nur der Wrist-Ast');
}

// --- Robustheit ------------------------------------------------------------

function test_zugauskunft_wird_gecacht()
{
    $q = t_standard(['zug:81871' => t_lauf_81871(['ursache' => 'Signalstörung'])]);
    t_build($q, 'APD', null, $calls1);
    t_build($q, 'APD', null, $calls2);
    t_eq(1, count(preg_grep('/zug=81871/', $calls1)), 'erster Lauf fragt den Zug ab');
    t_eq(0, count(preg_grep('/zug=81871/', $calls2)), 'zweiter Lauf nimmt den Cache');
    // Zuege ohne Antwort (hier: alle anderen) werden beim naechsten Lauf erneut versucht
    t_eq(1, count(preg_grep('/zug=75515/', $calls2)), 'fehlgeschlagene Abfrage wird wiederholt');
    t_eq('Signalstörung', t_zeile(t_build($q), 'hin', '81871')['hinweis'], 'Ursache auch aus dem Cache');
}

function test_abfragen_gebuendelt()
{
    // Tafeln und Zuege werden in zwei Buendeln geholt: erst die vier Bahnhoefe, dann alle Zuege
    t_build(t_standard(['zug:81871' => t_lauf_81871(['ursache' => 'Signalstörung'])]), 'APD', null, $calls);
    $arten = array_map(function ($u) { return strpos($u, '?zug=') !== false ? 'zug' : 'bhf'; }, $calls);
    t_eq(['bhf', 'bhf', 'bhf', 'bhf', 'bhf'], array_slice($arten, 0, 5), 'erst die fuenf Tafeln (Heimat, Hbf, Altona, Dammtor, Pinneberg)');
    t_eq(0, count(array_filter(array_slice($arten, 5), function ($a) { return $a === 'bhf'; })), 'danach nur Zuege');
    t_eq(count($calls), count(array_unique($calls)), 'keine URL doppelt');

    $antworten = abf_fetch_many(['a', 'b', 'a'], function ($u) { return strtoupper($u); });
    t_eq(['a' => 'A', 'b' => 'B'], $antworten, 'abf_fetch_many ohne curl: nacheinander, doppelte nur einmal');
}

function test_alter_stand_wird_sofort_geliefert()
{
    $now = strtotime('2026-10-15 10:00:00 Europe/Berlin');
    $stand = function ($sek, $ok = true) use ($now) {
        return ['ok' => $ok, 'fetched_at' => gmdate('c', $now - $sek), 'richtungen' => []];
    };
    t_eq(false, abf_sofort_liefern($stand(30), $now), 'frisch: normal ueber abf_cached');
    t_eq(true, abf_sofort_liefern($stand(90), $now), 'aelter als ABF_TTL: sofort liefern, dann erneuern');
    t_eq(false, abf_sofort_liefern($stand(ABF_STALE_TTL + 1), $now), 'zu alt: Besucher wartet auf frische Daten');
    t_eq(false, abf_sofort_liefern($stand(90, false), $now), 'kaputter Stand wird nicht geliefert');
    t_eq(false, abf_sofort_liefern(null, $now), 'ohne Cache');
}

function test_quellen_ausgefallen()
{
    t_eq(null, t_build([]), 'ohne jede Quelle keine Antwort');
    $r = t_build(t_standard(['APD' => null]));
    t_eq(false, $r['quellen']['APD'], 'Heimat-Tafel nicht erreichbar');
    t_eq([], t_zuege($r, 'hin'), 'Hinrichtung leer');
    t_eq(['28542', '75514', '81874'], t_zuege($r, 'rueck'), 'Rueckrichtung trotzdem da');
}

function test_kaputtes_xml_gilt_als_nicht_erreichbar()
{
    $r = t_build(t_standard(['AH' => '<html>Fehler 500</html>']));
    t_eq(false, $r['quellen']['AH'], 'Hbf-Tafel unbrauchbar');
    t_eq(['28542', '75514', '81874'], t_zuege($r, 'rueck'), 'Altona bleibt, die Hbf-Zuege stehen ab Dammtor');
    t_eq('Dammtor', t_zeile($r, 'rueck', '75514')['ab'], 'ab Dammtor');
}
