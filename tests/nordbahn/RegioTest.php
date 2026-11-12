<?php
/**
 * Tests fuer die DB-Regio-Zuege (Elmshorn, Glueckstadt, Itzehoe, Wrist, Pinneberg) (gf_regio_* in api/geofox.php):
 * Linien und Richtung, Gleiswechsel, Filter mit Rueckfall, Cache.
 */

function t_regio_antworten()
{
    return [
        'departureList:Elmshorn' => t_gf_departures([
            ['linie' => 'RE7', 'ziel' => 'Hamburg Hbf', 'offset' => 4, 'platform' => 'Gleis 3'],
            ['linie' => 'RE70', 'ziel' => 'Kiel Hbf', 'offset' => 6],                       // nach Norden: raus
            ['linie' => 'RE6', 'ziel' => 'Hamburg-Altona', 'offset' => 12, 'delay' => 420],
            ['linie' => 'RE70', 'ziel' => 'Hamburg Hbf', 'offset' => 34, 'cancelled' => true],
        ]),
        'departureList:Hamburg Hbf' => t_gf_departures([
            ['linie' => 'RE70', 'ziel' => 'Kiel Hbf', 'offset' => 9, 'platform' => 'Gleis 7'],
            ['linie' => 'RE7', 'ziel' => 'Flensburg', 'offset' => 39],
        ]),
        'departureList:Hamburg-Altona' => t_gf_departures([
            ['linie' => 'RE6', 'ziel' => 'Westerland (Sylt)', 'offset' => 20],
        ]),
    ];
}

function test_regio_richtungen_und_format()
{
    $calls = [];
    $r = gf_regio_build('AEL', t_gf_fetch(t_regio_antworten(), $calls), t_now(), t_gf_zugang());
    t_eq(true, $r['ok'], 'Stand gebaut');
    t_eq(['id' => 'AEL', 'name' => 'Elmshorn'], $r['heimat'], 'Heimatbahnhof');
    $hin = $r['richtungen']['hin'];
    t_eq(['RE7', 'RE6', 'RE70'], array_column($hin, 'linie'), 'nach Hamburg, Zuege nach Norden raus');
    t_eq('', $hin[0]['ab'], 'Hinweg ohne Startbahnhof wie die Nordbahn');
    t_eq('hvv', $hin[0]['quelle'], 'Quelle markiert');
    t_eq(7, $hin[1]['verspaetung'], 'Verspaetung in Minuten');
    t_eq('10:19', substr($hin[1]['ist'], 11, 5), 'Istzeit');
    t_eq(true, $hin[2]['ausfall'], 'Ausfall');
    $rueck = $r['richtungen']['rueck'];
    t_eq(['RE70', 'RE6', 'RE7'], array_column($rueck, 'linie'), 'Hbf und Altona nach Zeit gemischt');
    t_eq(['Hbf', 'Altona', 'Hbf'], array_column($rueck, 'ab'), 'Startbahnhof wie bei der Nordbahn');
    t_eq('7', $rueck[0]['gleis'], 'Gleis');
    // Antworten enthalten nur RE: der Linienfilter gilt als angenommen, kein zweiter Aufruf
    $dl = array_values(array_filter($calls, function ($c) { return $c[0] === 'departureList'; }));
    t_eq(3, count($dl), 'eine Anfrage je Station');
    t_eq([['serviceName' => 'RE6'], ['serviceName' => 'RE7'], ['serviceName' => 'RE70']], $dl[0][1]['filter'], 'Filter auf die drei Linien');
    t_eq(165, $dl[0][1]['maxTimeOffset'], 'am Heimatbahnhof zwei Stunden plus Fahrzeit ab Hamburg');
    t_eq(120, $dl[1][1]['maxTimeOffset'], 'in Hamburg zwei Stunden wie die Nordbahn-Tafel');
}

function test_regio_filter_ignoriert_dann_ungefiltert()
{
    // Liefert die GTI trotz Filter fremde Linien, war der Filter wirkungslos und die
    // Liste womoeglich zu kurz: noch einmal ohne Filter mit grosser Liste
    $antworten = t_regio_antworten();
    $antworten['departureList:Hamburg Hbf'] = t_gf_departures([
        ['linie' => 'U1', 'ziel' => 'Norderstedt Mitte', 'offset' => 1],
        ['linie' => 'RE8', 'ziel' => 'Lübeck Hbf', 'offset' => 2],
        ['linie' => 'RE7', 'ziel' => 'Kiel Hbf', 'offset' => 15],
        ['linie' => 'ICE', 'ziel' => 'Kiel Hbf', 'offset' => 16],
    ]);
    $calls = [];
    $r = gf_regio_build('AEL', t_gf_fetch($antworten, $calls), t_now(), t_gf_zugang());
    $hbf = array_values(array_filter($calls, function ($c) { return $c[0] === 'departureList' && $c[1]['station']['name'] === 'Hamburg Hbf'; }));
    t_eq(2, count($hbf), 'Hbf zweimal gefragt');
    t_eq(false, isset($hbf[1][1]['filter']), 'zweite Anfrage ohne Filter');
    t_eq(GF_REGIO_MAX_LIST, $hbf[1][1]['maxList'], 'mit grosser Liste');
    t_eq(['RE7', 'RE6'], array_column($r['richtungen']['rueck'], 'linie'), 'nur RE6, RE7, RE70, kein RE8 oder ICE');
}

function test_regio_gleiswechsel_und_fehler()
{
    $antworten = [
        'departureList:Elmshorn' => json_encode(['returnCode' => 'OK', 'time' => ['date' => '15.10.2026', 'time' => '10:00'], 'departures' => [
            ['line' => ['name' => 'RE7', 'direction' => 'Hamburg Hbf'], 'timeOffset' => 5, 'delay' => 0, 'platform' => 'Gleis 3', 'realtimePlatform' => 'Gleis 5'],
        ]]),
    ];
    $r = gf_regio_build('AEL', t_gf_fetch($antworten), t_now(), t_gf_zugang());
    t_eq('5', $r['richtungen']['hin'][0]['gleis'], 'heutiges Gleis');
    t_eq('3', $r['richtungen']['hin'][0]['gleis_plan'], 'Plangleis bei Wechsel');
    t_eq(['hin' => true, 'rueck' => false], $r['quellen'], 'Hamburg nicht erreichbar');
    t_eq([], $r['richtungen']['rueck'], 'Rueckweg leer');

    t_eq(null, gf_regio_build('AEL', t_gf_fetch([]), t_now(), t_gf_zugang()), 'alles kaputt: null');
    t_eq(null, gf_regio_build('APD', t_gf_fetch(t_regio_antworten()), t_now(), t_gf_zugang()), 'Prisdorf hat keine DB-Regio-Zuege');
}

function test_regio_cache()
{
    $now = t_now();
    $r = gf_regio_cached('AEL', t_gf_fetch(t_regio_antworten()), $now, t_gf_zugang());
    t_eq(true, $r['ok'], 'gebaut');
    t_eq(true, is_array(nordbahn_read_json(gf_regio_cache_file('AEL'))), 'Cache geschrieben');
    $r2 = gf_regio_cached('AEL', t_gf_fetch([]), (clone $now)->modify('+10 minutes'), t_gf_zugang());
    t_eq(false, $r2['ok'], 'Netzfehler: alter Stand mit ok=false');
    t_eq(3, count($r2['richtungen']['hin']), 'alter Inhalt bleibt');
    t_eq(gf_regio_leer(), gf_regio_cached('APD', t_gf_fetch(t_regio_antworten()), $now, t_gf_zugang()), 'fremder Bahnhof: leer');
}

function test_regio_nur_haltende_re_auf_dem_rueckweg()
{
    // Wrist: nur der RE70 um 10:39 ab Hbf haelt (an 11:21), der um 09:39 (10:09 ab Hbf
    // ist im Test der erste) nicht; RE7 haelt nie. Itzehoe: RE6 ab Altona.
    $antworten = [
        'departureList:Hamburg Hbf' => t_gf_departures([
            ['linie' => 'RE70', 'ziel' => 'Kiel Hbf', 'offset' => 9],
            ['linie' => 'RE7', 'ziel' => 'Flensburg', 'offset' => 39],
            ['linie' => 'RE70', 'ziel' => 'Kiel Hbf', 'offset' => 69],
        ]),
        'departureList:Hamburg-Altona' => t_gf_departures([
            ['linie' => 'RE6', 'ziel' => 'Westerland (Sylt)', 'offset' => 20],
            ['linie' => 'RE6', 'ziel' => 'Westerland (Sylt)', 'offset' => 80],
        ]),
        'departureList:Wrist' => t_gf_departures([
            ['linie' => 'RE70', 'ziel' => 'Hamburg Hbf', 'offset' => 14],
            ['linie' => 'RE70', 'ziel' => 'Kiel Hbf', 'offset' => 111],   // 69 + 42
        ]),
        'departureList:Itzehoe' => t_gf_departures([
            ['linie' => 'RE6', 'ziel' => 'Hamburg-Altona', 'offset' => 25],
            ['linie' => 'RE6', 'ziel' => 'Westerland (Sylt)', 'offset' => 68],   // 20 + 48
            ['linie' => 'RE6', 'ziel' => 'Westerland (Sylt)', 'offset' => 128],  // 80 + 48
        ]),
    ];
    $calls = [];
    $fetch = t_gf_fetch($antworten, $calls);
    $wrist = gf_regio_build('AWST', $fetch, t_now(), t_gf_zugang());
    t_eq(['RE70'], array_column($wrist['richtungen']['hin'], 'linie'), 'Wrist nach Hamburg');
    t_eq(['11:09'], array_map('t_uhr', array_column($wrist['richtungen']['rueck'], 'zeit')), 'nur der RE70, der in Wrist haelt');
    t_eq('Hbf', $wrist['richtungen']['rueck'][0]['ab'], 'ab Hbf');

    $itz = gf_regio_build('AIZ', $fetch, t_now(), t_gf_zugang());
    t_eq(['10:20', '11:20'], array_map('t_uhr', array_column($itz['richtungen']['rueck'], 'zeit')), 'beide RE6 ab Altona halten in Itzehoe');
    t_eq(['Altona', 'Altona'], array_column($itz['richtungen']['rueck'], 'ab'), 'ab Altona');
    t_eq('Hamburg-Altona', $itz['richtungen']['hin'][0]['ziel'], 'Itzehoe nach Altona');

    // Die Hamburger Tafeln kommen fuer den zweiten Bahnhof aus dem geteilten Cache
    $hbf = array_filter($calls, function ($c) { return $c[0] === 'departureList' && $c[1]['station']['name'] === 'Hamburg Hbf'; });
    t_eq(1, count($hbf), 'Hbf nur einmal gefragt');

    $pi = gf_regio_build('AP', t_gf_fetch(['departureList:Pinneberg' => t_gf_departures([])] + $antworten), t_now(), t_gf_zugang());
    t_eq([], $pi['richtungen']['rueck'], 'ohne Halt in Pinneberg kein RE auf dem Rueckweg');
}

function test_regio_laufweg_entscheidet_ueber_den_halt()
{
    // Zwei RE70 ab Hbf; laut Laufweg haelt nur der zweite in Wrist. Der Fahrzeit-Abgleich
    // wuerde hier beide verwerfen (Wrist meldet keine Abfahrt nach Norden), der Laufweg gewinnt.
    $dep = function ($offset, $sid, $ziel = 'Kiel Hbf') {
        return ['line' => ['name' => 'RE70', 'direction' => $ziel, 'origin' => 'Hamburg Hbf', 'id' => 'DB-EFZ:RE70_DB-EFZ_Z'], 'timeOffset' => $offset, 'delay' => 0, 'serviceId' => $sid, 'station' => ['combinedName' => 'Hamburg Hbf', 'id' => 'Master:9910950'], 'platform' => 'Gleis 7'];
    };
    $antworten = [
        'Hamburg Hbf'    => ['returnCode' => 'OK', 'time' => ['date' => '15.10.2026', 'time' => '10:00'], 'departures' => [$dep(9, 111), $dep(69, 222)]],
        'Hamburg-Altona' => ['returnCode' => 'OK', 'time' => ['date' => '15.10.2026', 'time' => '10:00'], 'departures' => []],
        'Wrist'          => ['returnCode' => 'OK', 'time' => ['date' => '15.10.2026', 'time' => '10:00'], 'departures' => []],
    ];
    $kurs = function (array $halte) {
        $el = [];
        for ($i = 0; $i < count($halte) - 1; $i++) {
            $el[] = ['fromStation' => ['name' => $halte[$i]], 'toStation' => ['name' => $halte[$i + 1]]];
        }
        return json_encode(['returnCode' => 'OK', 'courseElements' => $el]);
    };
    $kurse = [111 => $kurs(['Hamburg Hbf', 'Hamburg Dammtor', 'Elmshorn', 'Neumünster', 'Kiel Hbf']), 222 => $kurs(['Hamburg Hbf', 'Hamburg Dammtor', 'Elmshorn', 'Wrist', 'Neumünster', 'Kiel Hbf'])];
    $calls = [];
    $fetch = function ($method, $json) use ($antworten, $kurse, &$calls) {
        $body = json_decode($json, true);
        $calls[] = [$method, $body];
        if ($method === 'departureList') {
            $a = $antworten[$body['station']['name']] ?? null;
            return $a === null ? null : json_encode($a);
        }
        if ($method === 'departureCourse') {
            return $kurse[$body['serviceId']] ?? null;
        }
        if ($method === 'checkName') {
            return json_encode(['returnCode' => 'OK', 'results' => [['name' => $body['theName']['name'], 'id' => 'Master:1', 'type' => 'STATION']]]);
        }
        return null;
    };
    $r = gf_regio_build('AWST', $fetch, t_now(), t_gf_zugang());
    t_eq(['11:09'], array_map('t_uhr', array_column($r['richtungen']['rueck'], 'zeit')), 'nur der RE70 mit Wrist im Laufweg');
    t_eq(false, array_key_exists('_kurs', $r['richtungen']['rueck'][0]), 'interner Schluessel nicht in der Ausgabe');
    $dc = array_values(array_filter($calls, function ($c) { return $c[0] === 'departureCourse'; }));
    t_eq(2, count($dc), 'je Fahrt ein Laufweg');
    t_eq('DB-EFZ:RE70_DB-EFZ_Z', $dc[0][1]['lineKey'], 'Linien-ID aus der Abfahrt');
    t_eq('Master:9910950', $dc[0][1]['station']['id'], 'Station der Abfahrt');

    // Itzehoe danach: Laufwege aus dem Cache, keine neue Abfrage
    $calls = [];
    gf_regio_build('AIZ', $fetch, t_now(), t_gf_zugang());
    t_eq(0, count(array_filter($calls, function ($c) { return $c[0] === 'departureCourse'; })), 'Laufwege aus dem Cache');
}
