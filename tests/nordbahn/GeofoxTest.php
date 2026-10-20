<?php
/**
 * Tests fuer api/geofox.php (HVV-Schnittstelle): Signatur, Anfrage, Abfahrten,
 * Meldungen, Statusregel, Cache und Darstellung. Die Fixtures spiegeln das
 * Format, von dem die Entwicklung ausgeht; mit echten Antworten abgleichen.
 */

function test_geofox_signatur_bekannter_vektor()
{
    // HMAC-SHA1("The quick brown fox jumps over the lazy dog", "key") = de7c9b85b8b78aa6bc8a7a36f70a90701c9db4d9
    t_eq('3nybhbi3iqa8ino29wqQcBydtNk=', gf_signature('The quick brown fox jumps over the lazy dog', 'key'), 'Base64 des rohen HMAC-SHA1');
    $h = gf_headers('{}', t_gf_zugang());
    t_eq(true, in_array('geofox-auth-type: HmacSHA1', $h, true), 'auth-type');
    t_eq(true, in_array('geofox-auth-user: test', $h, true), 'auth-user');
    t_eq(true, (bool) preg_grep('/^geofox-auth-signature: [A-Za-z0-9+\/]+=*$/', $h), 'auth-signature');
}

function test_geofox_request_setzt_version_und_sprache()
{
    $calls = [];
    $fetch = t_gf_fetch(['init' => '{"returnCode":"OK","beginOfService":"2026-01-01"}'], $calls);
    $r = gf_request('init', [], $fetch, t_gf_zugang());
    t_eq('2026-01-01', $r['beginOfService'], 'Antwort durchgereicht');
    t_eq(59, $calls[0][1]['version'], 'Version aus dem Zugang');
    t_eq('de', $calls[0][1]['language'], 'Sprache');

    t_eq(null, gf_request('init', [], t_gf_fetch(['init' => '{"returnCode":"ERROR_TEXT","errorText":"kaputt"}']), t_gf_zugang()), 'Fehlercode wird null');
    t_eq(null, gf_request('init', [], t_gf_fetch(['init' => 'kein json']), t_gf_zugang()), 'kaputtes JSON wird null');
    t_eq(null, gf_request('init', [], t_gf_fetch([]), t_gf_zugang()), 'Netzfehler wird null');
}

function test_geofox_aus_ohne_zugang()
{
    t_eq(null, gf_zugang([]), 'ohne Konfiguration kein Zugang');
    t_eq(null, gf_zugang(['NORDBAHN_GEOFOX_USER' => 'x']), 'ohne Passwort kein Zugang');
    $z = gf_zugang(['NORDBAHN_GEOFOX_USER' => 'x', 'NORDBAHN_GEOFOX_PASSWORD' => 'y', 'NORDBAHN_GEOFOX_URL' => 'https://a.example/gti/public/', 'NORDBAHN_GEOFOX_VERSION' => '42']);
    t_eq('https://a.example/gti/public', $z['url'], 'URL ohne Schlussstrich');
    t_eq(42, $z['version'], 'Version aus der Konfiguration');
    t_eq('unknown', nb_status_s3(null)['level'], 'ohne Stand unbekannt');
    t_eq('S3-Lage unbekannt', nb_status_s3(['ok' => false, 's3' => null])['title'], 'kaputter Stand');
}

function test_s3_abfahrten_zeit_richtung_und_fenster()
{
    $now = t_now();
    $rows = [];
    for ($i = 0; $i < 8; $i++) {
        $rows[] = ['offset' => 5 + 10 * $i];
    }
    $rows[1]['delay'] = 300;
    $rows[2]['linie'] = 'S 5';
    $rows[3]['linie'] = 'RB61';
    $hbf = [['ziel' => 'Pinneberg', 'offset' => 3, 'platform' => 'Gleis 1 (S-Bahn)'], ['ziel' => 'Elbgaustraße', 'offset' => 6], ['ziel' => 'Pinneberg', 'offset' => 13, 'cancelled' => true, 'platform' => 'Gleis 14']];
    $calls = [];
    $fetch = t_gf_fetch(['departureList:Pinneberg' => t_gf_departures($rows), 'departureList:Hamburg Hbf' => t_gf_departures($hbf)], $calls);
    $r = gf_departures_s3($fetch, $now, t_gf_zugang());
    $ab = array_values(array_filter($r['liste'], function ($d) { return $d['ab'] === 'Pinneberg'; }));
    $hbfRows = array_values(array_filter($r['liste'], function ($d) { return $d['ab'] === 'Hamburg Hbf'; }));
    t_eq(6, count($ab), 'hoechstens sechs je Richtung, fremde Linien raus');
    t_eq('10:05', substr($ab[0]['zeit'], 11, 5), 'Planzeit aus timeOffset');
    t_eq(5, $ab[1]['delay_min'], 'delay in Sekunden wird Minuten');
    t_eq('10:20', substr($ab[1]['ist'], 11, 5), 'Istzeit mit Verspaetung');
    t_eq('1', $ab[0]['gleis'], 'Gleis ohne Wort');
    t_eq(['Pinneberg', 'Pinneberg'], array_column($hbfRows, 'ziel'), 'am Hbf nur Richtung Pinneberg');
    t_eq(true, $hbfRows[1]['ausfall'], 'Ausfall uebernommen');
    t_eq('14', $hbfRows[1]['gleis'], 'Gleis am Hbf');
    t_eq('1', $hbfRows[0]['gleis'], 'Zusatz "(S-Bahn)" entfernt');
    t_eq(['hin' => true, 'rueck' => true], $r['quellen'], 'beide Quellen');
    // Stationen wurden per checkName aufgeloest und gecacht
    t_eq(['checkName', 'departureList', 'checkName', 'departureList'], array_column($calls, 0), 'je Station erst checkName, dann departureList');
    t_eq(true, strpos($calls[1][1]['station']['id'] ?? '', 'Master:') === 0, 'departureList mit ID');
    $calls = [];
    gf_departures_s3(t_gf_fetch(['departureList:Pinneberg' => t_gf_departures($rows)], $calls), $now, t_gf_zugang());
    t_eq(['departureList', 'departureList', 'departureList'], array_column($calls, 0), 'Stationen aus dem Tagescache, kein checkName mehr; Hbf ohne Antwort wird ohne Linienfilter wiederholt');
    t_eq([['serviceID' => 'SBH:S3_SBH_SBAHNS']], $calls[0][1]['filter'], 'erste Anfrage nur die S3');
    t_eq(false, isset($calls[2][1]['filter']), 'Wiederholung ohne Linienfilter');
    t_eq(150, $calls[2][1]['maxList'], 'Wiederholung mit grosser Liste');
    // Filter angenommen, aber leer (z. B. abgelehnt mit OK ohne Zeilen): ungefiltert nachfragen
    $calls = [];
    $leerDannVoll = function ($method, $json) use (&$calls) {
        $body = json_decode($json, true);
        $calls[] = $body;
        if ($method !== 'departureList') {
            return null;
        }

        return isset($body['filter']) ? t_gf_departures([]) : t_gf_departures([['offset' => 3, 'ziel' => 'Pinneberg']]);
    };
    $r = gf_departures_s3($leerDannVoll, $now, t_gf_zugang());
    t_eq(4, count($calls), 'je Richtung gefiltert und ungefiltert');
    t_eq(2, count($r['liste']), 'Zeilen aus der ungefilterten Antwort');
    $calls = [];
    $r = gf_departures_s3(t_gf_fetch(['departureList:Pinneberg' => t_gf_departures($rows), 'departureList:Hamburg-Altona' => t_gf_departures([['ziel' => 'Pinneberg', 'offset' => 2]])], $calls), $now, t_gf_zugang(), 'altona');
    t_eq(['departureList', 'checkName', 'departureList'], array_column($calls, 0), 'neuer Stadtbahnhof: nur Altona wird aufgeloest');
    t_eq('Hamburg-Altona', $calls[2][1]['station']['name'], 'Rueckrichtung ab Altona');
    t_eq('Hamburg-Altona', end($r['liste'])['ab'], 'Abfahrtsort Altona');
    $calls = [];
    $r = gf_departures_s3(t_gf_fetch(['departureList:Pinneberg' => t_gf_departures($rows), 'departureList:Stadthausbrücke' => t_gf_departures([['ziel' => 'Pinneberg', 'offset' => 4]])], $calls), $now, t_gf_zugang(), 'stadthausbruecke');
    t_eq('Stadthausbrücke', end($r['liste'])['ab'], 'Abfahrtsort Stadthausbruecke');
    t_eq(['altona', 'stadthausbruecke', 'jungfernstieg', 'hbf'], array_keys(GF_STADT), 'Stadtbahnhoefe in Fahrtrichtung');

    t_eq(null, gf_departures_s3(t_gf_fetch([]), $now, t_gf_zugang()), 'beide Quellen tot');
}

function test_s3_status_stufen()
{
    $now = t_now()->getTimestamp();
    $ok = [];
    for ($i = 0; $i < 12; $i++) {
        $ok[] = ['ausfall' => false, 'delay_min' => 0];
    }
    t_eq(['level' => 'ok', 'title' => 'S3 fährt planmäßig', 'detail' => ''], gf_status($ok, [], $now), 'alles puenktlich');

    $einer = $ok;
    $einer[0]['ausfall'] = true;
    t_eq(['level' => 'warn', 'title' => 'S3 beeinträchtigt', 'detail' => '1 von 12 Zügen (8 %): 1 Ausfall'], gf_status($einer, [], $now), 'ein Ausfall');

    $viele = $ok;
    $viele[0]['ausfall'] = true;
    $viele[1]['ausfall'] = true;
    $viele[2]['delay_min'] = 7;
    t_eq(['level' => 'alert', 'title' => 'S3 gestört', 'detail' => '3 von 12 Zügen (25 %): 2 Ausfälle, Verspätungen bis 7 Min'], gf_status($viele, [], $now), 'ein Viertel betroffen');

    $akut = [['titel' => 'S3: Signalstörung Eidelstedt', 'geplant' => false, 'aktiv' => true, 'linien' => ['S3']]];
    t_eq(['level' => 'alert', 'title' => 'S3 gestört', 'detail' => 'S3: Signalstörung Eidelstedt'], gf_status($ok, $akut, $now), 'ungeplante aktive S3-Meldung');
    $geplant = [['titel' => 'Bauarbeiten', 'geplant' => true, 'aktiv' => true, 'linien' => ['S3']]];
    t_eq(['level' => 'ok', 'title' => 'S3 fährt planmäßig', 'detail' => ''], gf_status($ok, $geplant, $now), 'geplante Meldung faerbt nicht und steht nicht in der Karte');
    $rb = [['titel' => 'RB61: Weichenstörung', 'geplant' => false, 'aktiv' => true, 'linien' => ['RB61', 'RB71']]];
    t_eq(['level' => 'ok', 'title' => 'S3 fährt planmäßig', 'detail' => ''], gf_status($ok, $rb, $now), 'ungeplante Nordbahn-Meldung beruehrt die S3-Karte nicht');
    $alt = [['titel' => 'Vorbei', 'geplant' => false, 'aktiv' => false]];
    t_eq('ok', gf_status($ok, $alt, $now)['level'], 'abgelaufene Meldung zaehlt nicht');
    t_eq(['level' => 'ok', 'title' => 'S3: keine Abfahrten gemeldet', 'detail' => ''], gf_status([], [], $now), 'nachts ohne Abfahrten');
}

function test_s3_meldung_normalisierung()
{
    $now = t_now();
    $fetch = t_gf_fetch(['getAnnouncements' => t_gf_announcements([
        ['id' => 'B', 'summary' => 'S3: <b>Bauarbeiten</b>', 'description' => '<p>Wegen <b>Bauarbeiten</b> zwischen Pinneberg &amp; Elmshorn<br>fahren Busse.</p><p>Bitte Zeit einplanen.</p>', 'planned' => true, 'begin' => '2026-10-17T22:00:00.000+02:00', 'end' => '2026-10-20T04:00:00.000+02:00', 'lines' => ['S 3']],
        ['id' => 'U', 'summary' => 'S3: Signalstörung', 'planned' => false, 'begin' => '2026-10-15T09:30:00.000+02:00', 'end' => '2026-10-15T12:00:00.000+02:00', 'lines' => ['S3', 'RB 61']],
        ['id' => 'F', 'summary' => 'S1: Nur S1', 'planned' => false, 'lines' => ['S1']],
        ['id' => 'P', 'summary' => 'Ersatzverkehr laeuft', 'planned' => true, 'begin' => '2026-10-15T05:00:00.000+02:00', 'end' => '2026-10-15T23:00:00.000+02:00', 'lines' => ['RB71']],
        ['id' => 'A', 'summary' => 'Alt', 'planned' => false, 'begin' => '2026-10-14T05:00:00.000+02:00', 'end' => '2026-10-14T08:00:00.000+02:00'],
        ['id' => 'L', 'summary' => 'Pinneberg: Aufzug zu Gleis 1 defekt', 'planned' => false, 'begin' => '2026-10-15T05:00:00.000+02:00', 'end' => '2026-10-16T08:00:00.000+02:00', 'lines' => ['S3']],
        ['id' => 'O', 'summary' => 'Ohne Linie', 'planned' => false, 'lines' => []],
    ])]);
    $m = gf_announcements($fetch, $now, GF_LINIEN, t_gf_zugang());
    t_eq(['U', 'P', 'B', 'A'], array_column($m, 'id'), 'fremde Linie, Aufzug und ohne Linie raus; aktive ungeplante, aktive geplante, dann nach Beginn absteigend');
    $bodies = [];
    gf_announcements(t_gf_fetch(['getAnnouncements' => t_gf_announcements([])], $bodies), $now, GF_LINIEN, t_gf_zugang());
    t_eq('2026-10-15T09:00:00.000+0200', $bodies[0][1]['timeRange']['begin'], 'Zeitraum im Format der GTI');
    $b = $m[2];
    t_eq('S3: Bauarbeiten', $b['titel'], 'HTML im Titel entfernt');
    t_eq("Wegen Bauarbeiten zwischen Pinneberg & Elmshorn\nfahren Busse.\nBitte Zeit einplanen.", $b['text'], 'Text ohne HTML, Umbrueche bleiben');
    t_eq(true, $b['geplant'], 'geplant');
    t_eq(false, $b['aktiv'], 'noch nicht gueltig');
    t_eq('2026-10-17T20:00:00Z', $b['von'], 'Beginn in UTC');
    t_eq('2026-10-20T02:00:00Z', $b['bis'], 'Ende in UTC');
    t_eq(['S3'], $b['linien'], 'Linienname ohne Leerzeichen');
    t_eq(['S3', 'RB61'], $m[0]['linien'], 'mehrere Linien');
    t_eq(true, $m[0]['aktiv'], 'jetzt gueltig');
    t_eq('2026-10-15T05:00:00Z', $m[0]['stand'], 'Stand als UTC');
    t_eq(null, gf_announcements(t_gf_fetch([]), $now, GF_LINIEN, t_gf_zugang()), 'Netzfehler wird null');

    // Lehnt die GTI den Zeitraum ab, kommt die naechste Anfrageform dran
    $bodies = [];
    $stur = function ($method, $json) use (&$bodies) {
        $body = json_decode($json, true);
        $bodies[] = $body;
        if (isset($body['timeRange'])) {
            return '{"returnCode":"ERROR_TEXT","errorText":"timeRange"}';
        }

        return t_gf_announcements([['id' => 'X', 'summary' => 'Geht', 'lines' => ['S3']]]);
    };
    $m = gf_announcements($stur, $now, GF_LINIEN, t_gf_zugang());
    t_eq(['X'], array_column($m, 'id'), 'zweite Anfrageform liefert');
    t_eq(2, count($bodies), 'nur bis zur ersten brauchbaren Antwort');
    t_eq(['S3', 'RB61', 'RB71'], $bodies[1]['names'], 'zweite Form nur mit Linien');
}

function test_s3_cache_und_stale()
{
    $now = t_now();
    $antworten = ['departureList:Pinneberg' => t_gf_departures([['offset' => 4]]), 'departureList:Hamburg Hbf' => t_gf_departures([['ziel' => 'Pinneberg', 'offset' => 9]]), 'getAnnouncements' => t_gf_announcements([])];
    $r = gf_cached(t_gf_fetch($antworten), $now, t_gf_zugang());
    t_eq(true, $r['ok'], 'Stand gebaut');
    t_eq('ok', $r['s3']['level'], 'Status im Stand');
    t_eq(2, count($r['s3']['abfahrten']), 'beide Richtungen');
    t_eq(['hin' => true, 'rueck' => true, 'meldungen' => true], $r['quellen'], 'Quellen');
    t_eq(true, is_array(nordbahn_read_json(GF_CACHE)), 'Cache geschrieben');

    $ts = $now->getTimestamp();
    t_eq(false, nordbahn_sofort_liefern($r, GF_TTL, GF_STALE_TTL, $ts + 30), 'frisch: normal liefern');
    t_eq(true, nordbahn_sofort_liefern($r, GF_TTL, GF_STALE_TTL, $ts + 90), 'alt: sofort liefern und erneuern');
    t_eq(false, nordbahn_sofort_liefern($r, GF_TTL, GF_STALE_TTL, $ts + 181), 'zu alt: neu holen');

    $spaeter = (clone $now)->modify('+10 minutes');
    $r2 = gf_cached(t_gf_fetch([]), $spaeter, t_gf_zugang());
    t_eq(false, $r2['ok'], 'Netzfehler: alter Stand mit ok=false');
    t_eq(2, count($r2['s3']['abfahrten']), 'alter Inhalt bleibt');

    $r3 = gf_cached(t_gf_fetch(['departureList:Pinneberg' => t_gf_departures([]), 'getAnnouncements' => t_gf_announcements([])]), $spaeter, t_gf_zugang());
    t_eq(true, $r3['ok'], 'eine Richtung reicht');
    t_eq(['hin' => true, 'rueck' => false, 'meldungen' => true], $r3['quellen'], 'Hbf als fehlend markiert');
    t_eq('S3: keine Abfahrten gemeldet', $r3['s3']['title'], 'keine Abfahrten');
    t_eq(['id' => 'hbf', 'name' => 'Hamburg Hbf', 'kurz' => 'Hbf'], $r3['stadt'], 'Stadtbahnhof im Stand');

    // Anderer Stadtbahnhof: eigener Cache, Meldungen aus dem geteilten Cache ohne neuen Aufruf
    $calls = [];
    $r4 = gf_cached(t_gf_fetch(['departureList:Pinneberg' => t_gf_departures([['offset' => 4]]), 'departureList:Hamburg-Altona' => t_gf_departures([['ziel' => 'Pinneberg', 'offset' => 7]])], $calls), $spaeter, t_gf_zugang(), 'altona');
    t_eq('altona', $r4['stadt']['id'], 'Stand fuer Altona');
    t_eq(false, in_array('getAnnouncements', array_column($calls, 0), true), 'Meldungen kommen aus dem geteilten Cache');
    t_eq(true, is_array(nordbahn_read_json(gf_cache_file('altona'))), 'eigene Cache-Datei');
    t_eq('hbf', nordbahn_read_json(GF_CACHE)['stadt']['id'], 'Standard-Cache unveraendert');
}

function test_geofox_diagnose_ohne_zugangsdaten()
{
    $calls = [];
    $fetchRoh = function ($method, $json) use (&$calls) {
        $calls[] = $method;
        if ($method === 'init') {
            return ['status' => 200, 'body' => '{"returnCode":"OK","version":59}'];
        }
        if ($method === 'checkName') {
            return ['status' => 403, 'body' => 'Forbidden'];
        }
        if ($method === 'departureList') {
            $j = json_decode(t_gf_departures([['offset' => 4]]), true);
            $j['departures'][0]['serviceId'] = 86296;
            $j['departures'][0]['line']['id'] = 'SBH:S3_SBH_SBAHNS';

            return ['status' => 200, 'body' => json_encode($j)];
        }
        if ($method === 'departureCourse') {
            return ['status' => 200, 'body' => '{"returnCode":"OK","courseElements":[]}'];
        }

        return ['status' => 0, 'body' => ''];
    };
    $d = gf_diagnose(t_gf_zugang(), t_now(), $fetchRoh);
    t_eq('https://gti.example/gti/public', $d['url'], 'Adresse');
    t_eq(['init', 'checkName', 'checkName', 'checkName', 'checkName', 'checkName', 'departureList', 'departureList', 'getAnnouncements', 'getAnnouncements', 'getAnnouncements', 'departureCourse'], $calls, 'alle Methoden, Meldungen in drei Formen, Zuglauf der ersten S3');
    $kurs = $d['aufrufe']['departureCourse erste S3 ab Pinneberg'];
    t_eq(86296, $kurs['anfrage']['serviceId'], 'Zuglauf zur ersten S3');
    t_eq('SBH:S3_SBH_SBAHNS', $kurs['anfrage']['lineKey'], 'Linienschluessel');
    t_eq('2026-10-15T10:04:00.000+0200', $kurs['anfrage']['time'], 'Abfahrtszeit');
    t_eq(['returnCode' => 'OK', 'version' => 59], $d['aufrufe']['init']['antwort'], 'dekodierte Antwort');
    t_eq(403, $d['aufrufe']['checkName Pinneberg']['status'], 'HTTP-Status');
    t_eq('Forbidden', $d['aufrufe']['checkName Pinneberg']['antwort'], 'Rohtext bei Nicht-JSON');
    t_eq(59, $d['aufrufe']['departureList Pinneberg gefiltert']['anfrage']['version'], 'Anfrage mit Version');
    t_eq(false, strpos(json_encode($d), 'geheim') !== false, 'Passwort erscheint nicht');
}

function test_lage_markdown_s3()
{
    $now = t_now()->getTimestamp();
    $gf = ['ok' => true, 'fetched_at' => '2026-10-15T09:59:00+02:00', 'stadt' => ['id' => 'hbf', 'name' => 'Hamburg Hbf', 'kurz' => 'Hbf'], 's3' => ['level' => 'warn', 'title' => 'S3 beeinträchtigt', 'detail' => '1 von 2 Zügen (50 %): 1 Ausfall',
        'abfahrten' => [['zeit' => '2026-10-15T10:07:00+02:00', 'ist' => '2026-10-15T10:07:00+02:00', 'linie' => 'S3', 'ziel' => 'Stade', 'ab' => 'Pinneberg', 'delay_min' => 0, 'ausfall' => false, 'gleis' => '1'], ['zeit' => '2026-10-15T10:11:00+02:00', 'ist' => '2026-10-15T10:11:00+02:00', 'linie' => 'S3', 'ziel' => 'Pinneberg', 'ab' => 'Hamburg Hbf', 'delay_min' => 0, 'ausfall' => true, 'gleis' => '14']],
        'meldungen' => [['id' => 'B', 'titel' => 'S3: Bauarbeiten', 'text' => 'Busse fahren.', 'geplant' => true, 'aktiv' => true, 'von' => '2026-10-15T03:00:00Z', 'bis' => '2026-10-17T02:00:00Z', 'linien' => ['S3'], 'stand' => '']]]];
    $data = ['received_at' => '2026-10-15T07:58:00Z', 'stoerungen' => [], 'eilmeldungen_live' => [], 'eilmeldungen' => [], 'stoerungen_verlauf' => []];
    $md = nb_lage_md($data, null, 'APD', $now, $gf);
    t_contains('- S3 Pinneberg – Hamburg: S3 beeinträchtigt (1 von 2 Zügen (50 %): 1 Ausfall)', $md, 'Zeile in der Uebersicht');
    t_contains("## S3 zwischen Pinneberg und Hamburg (HVV)\n\n### S3 ab Pinneberg → Hamburg\n\n- 10:07 Uhr: S3 nach Stade. Gleis 1.\n", $md, 'Abfahrt ab Pinneberg');
    t_contains('- 10:11 Uhr: S3 nach Pinneberg, fällt aus. Gleis 14.', $md, 'Ausfall am Hbf');
    t_contains('- geplant, bis 17.10., 04:00 Uhr (S3): S3: Bauarbeiten. Busse fahren.', $md, 'Meldung');
    t_contains('hvv.de über die Geofox-Schnittstelle', $md, 'Quelle');
    $ohne = nb_lage_md($data, null, 'APD', $now);
    t_eq(false, strpos($ohne, 'S3 zwischen') !== false, 'ohne Geofox kein Abschnitt');
    t_eq(false, strpos($ohne, 'hvv.de') !== false, 'ohne Geofox keine Quelle');
}

function test_render_s3_html()
{
    $now = t_now()->getTimestamp();
    $liste = [['zeit' => '2026-10-15T10:07:00+02:00', 'ist' => '2026-10-15T10:12:00+02:00', 'linie' => 'S3', 'ziel' => 'Stade', 'ab' => 'Pinneberg', 'delay_min' => 5, 'ausfall' => false, 'gleis' => '1'],
        ['zeit' => '2026-10-15T10:17:00+02:00', 'ist' => '2026-10-15T10:17:00+02:00', 'linie' => 'S3', 'ziel' => 'Stade', 'ab' => 'Pinneberg', 'delay_min' => 0, 'ausfall' => true, 'gleis' => ''],
        ['zeit' => '2026-10-15T10:11:00+02:00', 'ist' => '2026-10-15T10:11:00+02:00', 'linie' => 'S3', 'ziel' => 'Pinneberg', 'ab' => 'Hamburg Hbf', 'delay_min' => 0, 'ausfall' => false, 'gleis' => '14']];
    $html = nb_render_s3_abfahrten($liste);
    t_contains('<li class="dep dep--late dep--next"><span class="dep__dot"></span><div class="dep__time">10:07 Uhr<small>+5 → 10:12 Uhr</small></div><div class="dep__line">S3</div><div class="dep__dest"><span class="dep__ziel">Stade</span></div><div class="dep__platform">Gl. 1</div></li>', $html, 'verspaetete Abfahrt mit Gleisspalte');
    t_contains('<li class="dep dep--cancelled"><span class="dep__dot"></span><div class="dep__time">10:17 Uhr<small>fällt aus</small></div>', $html, 'Ausfall');
    t_eq(false, strpos($html, 'Pinneberg</span>') !== false, 'Hbf-Zeilen nicht auf der Startseite');
    t_contains('Keine S3-Abfahrt in den nächsten 60 Minuten gemeldet.', nb_render_s3_abfahrten([]), 'leer');

    $m = [['id' => 'U', 'titel' => 'S3: Signalstörung', 'text' => 'Es kommt zu Ausfällen.', 'geplant' => false, 'aktiv' => true, 'von' => '2026-10-15T07:30:00Z', 'bis' => '2026-10-15T10:00:00Z', 'linien' => ['S3', 'RB61'], 'stand' => ''],
        ['id' => 'B', 'titel' => 'Bauarbeiten', 'text' => '', 'geplant' => true, 'aktiv' => false, 'von' => '2026-10-17T20:00:00Z', 'bis' => '2026-10-20T02:00:00Z', 'linien' => ['S3'], 'stand' => '']];
    $html = nb_render_s3_meldungen($m, $now);
    t_contains('<li class="is-heute"><div class="feed__time">Heute, 09:30 Uhr</div><div><p class="feed__kicker feed__kicker--hvv">HVV-Meldung <span class="dep__ab dep__ab--aktiv">aktuell</span></p><p class="feed__title">S3: Signalstörung</p><p class="feed__meta">S3, RB61 · bis 12:00 Uhr<br>Es kommt zu Ausfällen.</p></div></li>', $html, 'aktuelle Meldung');
    t_contains('<p class="feed__kicker feed__kicker--hvv">HVV-Meldung <span class="dep__ab dep__ab--plan">geplant</span></p><p class="feed__title">Bauarbeiten</p><p class="feed__meta">S3 · ab 17.10., 22:00 Uhr bis 20.10., 04:00 Uhr</p>', $html, 'geplante Meldung');
    t_contains('Keine HVV-Meldung zu S3, RB61 und RB71.', nb_render_s3_meldungen([], $now), 'leer');
    t_eq(1, substr_count(nb_render_s3_meldungen($m, $now, 1), '<li'), 'Limit');
    $hbf = nb_render_s3_abfahrten($liste, 'Hamburg Hbf');
    t_contains('<span class="dep__ziel">Pinneberg</span></div><div class="dep__platform">Gl. 14</div>', $hbf, 'Rueckrichtung ab Hbf');
}

function test_s3_meldungen_echte_antwort_korridor()
{
    // Echte Antwort von getAnnouncements (names S3, RB61, RB71) vom 20.10.2026
    $roh = file_get_contents(__DIR__ . '/../fixtures-geofox-meldungen.json');
    $now = new DateTime('2026-10-20 15:19:00', new DateTimeZone('Europe/Berlin'));
    $m = gf_announcements(t_gf_fetch(['getAnnouncements' => $roh]), $now, GF_LINIEN, t_gf_zugang());
    t_eq(['12.10-25.10. Fahrplanänderungen und Ersatzverkehr', '26.10.-02.11. alle Züge fahren ab/bis Altona, EV'], array_column($m, 'titel'), 'nur Nordbahn-Meldungen; S3 bei Stade/Neugraben, Aufzuege und Erreichbarkeit raus');
    t_eq(true, $m[0]['aktiv'], 'laufende Bauarbeiten aktiv');
    t_eq(true, $m[0]['geplant'], 'geplant');
    t_eq(['RB61', 'RB71'], array_values(array_intersect($m[0]['linien'], GF_LINIEN)), 'Linien aus locations');
    t_eq('2026-10-11T22:00:00Z', $m[0]['von'], 'Beginn aus validities mit +0200');
    t_eq(true, strpos($m[0]['link'], 'https://www.nordbahn.de/') === 0, 'Link zur Meldung');
    t_eq(false, $m[1]['aktiv'], 'Altona-Sperrung noch nicht gueltig');
    t_eq('ok', gf_status([['ausfall' => false, 'delay_min' => 0]], $m, $now->getTimestamp())['level'], 'geplante Meldungen faerben nicht');
}

function test_s3_station_norm_und_korridor()
{
    t_eq('hauptbahnhof', gf_station_norm('Hamburg Hbf'), 'Hbf');
    t_eq('altona', gf_station_norm('Hamburg-Altona'), 'Altona');
    t_eq('elbgaustrasse', gf_station_norm('Elbgaustraße'), 'Umlaut und ß');
    t_eq('jungfernstieg', gf_station_norm('Jungfernstieg'), 'Jungfernstieg');
    t_eq('stadthausbruecke', gf_station_norm('Stadthausbrücke'), 'Stadthausbruecke');
    $raw = ['locations' => [
        ['line' => ['name' => 'S3'], 'begin' => ['name' => 'Stade'], 'end' => ['name' => 'Pinneberg']],
        ['line' => ['name' => 'S5'], 'begin' => ['name' => 'Elbgaustraße'], 'end' => ['name' => 'Elbgaustraße']],
    ]];
    t_eq(['S3', 'S5'], gf_meldung_linien($raw), 'S3 ueber die ganze Linie zaehlt');
    $raw['locations'][0]['end']['name'] = 'Neugraben';
    t_eq(['S5'], gf_meldung_linien($raw), 'S3 nur suedlich der Elbe zaehlt nicht');
}

function test_s3_abfahrten_echte_antwort()
{
    $roh = json_decode(file_get_contents(__DIR__ . '/../fixtures-geofox-abfahrten.json'), true);
    $now = new DateTime('2026-10-20 15:19:00', new DateTimeZone('Europe/Berlin'));
    $fetch = t_gf_fetch([
        'departureList:Pinneberg'   => json_encode($roh['departureList Pinneberg gefiltert']),
        'departureList:Hamburg Hbf' => json_encode($roh['departureList Hamburg Hbf gefiltert']),
    ]);
    $r = gf_departures_s3($fetch, $now, t_gf_zugang());
    $hin = array_values(array_filter($r['liste'], function ($d) { return $d['ab'] === 'Pinneberg'; }));
    $rueck = array_values(array_filter($r['liste'], function ($d) { return $d['ab'] === 'Hamburg Hbf'; }));
    t_eq(6, count($hin), 'sechs ab Pinneberg');
    t_eq(['15:19', '15:29', '15:39'], array_map(function ($d) { return substr($d['zeit'], 11, 5); }, array_slice($hin, 0, 3)), 'Zehn-Minuten-Takt');
    t_eq(['Pinneberg'], array_values(array_unique(array_column($rueck, 'ziel'))), 'am Hbf nur Richtung Pinneberg');
    t_eq(1, $rueck[0]['delay_min'], '60 Sekunden Verspaetung');
    t_eq('1', $rueck[0]['gleis'], 'Gleis ohne Zusatz');
}
