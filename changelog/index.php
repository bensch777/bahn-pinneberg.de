<?php
/**
 * Changelog des Nordbahn Monitors: alle Aenderungen nach Datum, nur aus dem
 * Footer verlinkt. Neue Eintraege oben anfuegen.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$changelog = [
    ['2026-10-21', [
        ['S3-Tafeln nach Tageszeit', 'Wie bei der Nordbahn steht auch bei der S3 vormittags die Fahrt in die Stadt oben und ab 12 Uhr die Rückfahrt nach Pinneberg.'],
        ['Abfahrtstafel ohne Zugbilanz', 'Die graue Zeile „Letzte N Tage: …“ unter jeder Nordbahn-Abfahrt ist weg. Wie zuverlässig ein Zug in den letzten 30 Tagen war, steht weiter auf der Statistikseite.'],
    ]],
    ['2026-10-20', [
        ['Einheitliche Lagekarten für Nordbahn und S3', 'Die Karte zur Fahrplanlage heißt jetzt wie die S3-Karte nach der Linie: „Nordbahn fährt planmäßig“, „Nordbahn beeinträchtigt“, „Nordbahn gestört“, analog „S3 fährt planmäßig“ und so weiter.'],
        ['S-Bahn S3 und HVV-Meldungen', 'Neue Lagekarte „S3 Pinneberg – Hamburg“ aus den Echtzeit-Abfahrten der S3, dazu zwei Tafeln: Pinneberg Richtung Stadt und vom gewählten Stadtbahnhof (Altona, Stadthausbrücke, Jungfernstieg oder Hauptbahnhof) zurück nach Pinneberg. Darunter die neue Liste „Letzte HVV-Meldungen“ zu S3, RB61 und RB71. Quelle ist die Schnittstelle des HVV (Geofox). Geplante Meldungen wie Bauarbeiten stehen in der Liste, färben die Karte aber nicht ein. Auch in lage.md und unter api/s3.'],
    ]],
    ['2026-10-19', [
        ['Rubrik „Wichtige Meldungen“', 'Unter /news/ stehen nur noch die Störungen und Eilmeldungen, die viele Pendler betreffen: schwere Einschränkungen, Ausfälle, Umleitungen, Ersatzverkehr, Sperrungen, Bauarbeiten oder Meldungen zu mehreren Zügen. Die Startseite zeigt die wichtigen Meldungen der letzten 24 Stunden oberhalb der Abfahrten. Die Regeln stehen in den häufigen Fragen.'],
        ['Ereignisseiten im Artikel-Layout mit lesbaren Adressen', 'Störungen und Eilmeldungen haben jetzt eine Lesespalte mit Vorspann, Faktenbox, Originalmeldung als Zitat, Zeitleiste und Teilen-Zeile. Die Adressen tragen den Titel, etwa /eilmeldung/em-123456/rb61-elmshorn-altona-faellt-aus/. Alte Links ohne Titel leiten weiter.'],
    ]],
    ['2026-10-18', [
        ['Eine Seite je Störung und je Eilmeldung', 'Jede Infrastrukturstörung hat jetzt eine eigene Seite mit Pendler-Text, Originalmeldung, Verlauf (neuer Stand, geändertes Ende), den auffälligen Zügen im Zeitraum, Eilmeldungen und weiteren Störungen zur selben Zeit. Eilmeldungen bekommen ebenfalls eine Seite mit Pendler-Text, Nordbahn-Titel und der Lage zu dieser Zeit. Alle Listen, die Störungskarte und die Push-Nachrichten verlinken dorthin, die Seiten stehen in der Sitemap.'],
    ]],
    ['2026-10-17', [
        ['Züge ab Pinneberg in der Rückrichtung', 'Nachts beginnen die Züge nach Norden regulär in Pinneberg und standen deshalb nicht unter „Hamburg → Prisdorf“. Jetzt liest die Seite zusätzlich die Pinneberger Tafel (für die Äste auch Elmshorn) und zeigt diese Züge als „ab Pinneberg“ mit Hinweis „Beginnt planmäßig in Pinneberg, aus Hamburg mit der S-Bahn“. Die Zugauskunft bestätigt den Start, Züge, die Hamburg schon verlassen haben, erscheinen nicht doppelt.'],
        ['Nachtzüge und Busse vollständig', 'Der Stationsmonitor der Nordbahn trägt bei Abfahrten kurz nach Mitternacht manchmal das Datum von gestern; diese Züge und Ersatzbusse fehlten dann in der Liste. Das wird jetzt erkannt. Züge, die planmäßig nur bis Pinneberg fahren (nachts, weiter mit der S-Bahn), stehen als „nur bis Pinneberg“ ohne Warnfarbe in der Liste und zählen nicht als Störung, auch nicht in der Zuverlässigkeit je Zug.'],
        ['Züge nach Pinneberg zählen als Züge nach Hamburg', 'Wenden die Züge wegen einer Störung in Pinneberg, führt die Tafel sie mit Ziel „Pinneberg“. Bisher fielen sie damit aus der Liste Richtung Hamburg, die Seite zeigte „Keine Abfahrt“. Jetzt stehen sie drin, markiert als „nur bis Pinneberg“, ab dort geht es mit der S-Bahn weiter. Gilt allgemein für jedes Ziel, das vom eigenen Bahnhof aus Richtung Hamburg liegt.'],
    ]],
    ['2026-10-16', [
        ['Zuverlässigkeit je Zug', 'Die Seite merkt sich bei jeder Abfrage je Tag und Zug den letzten Stand vor der Abfahrt. Nach fünf beobachteten Tagen steht unter der Abfahrtszeile „Letzte 30 Tage: 2× ausgefallen, Ø +3 min“, und die Statistikseite zeigt je Bahnhof eine Tabelle aller Züge mit Ausfällen, Teilausfällen, Verspätungen ab 5 Minuten, Durchschnitt und Maximum. Die Sammlung beginnt mit diesem Update, die ersten Werte gibt es also in einer Woche.'],
        ['Für KI-Assistenten lesbar', 'Unter /llms.txt beschreibt die Seite sich selbst samt Datenfeldern, unter /lage.md gibt es die aktuelle Lage als Text: Status, aktive Störungen, nächste Züge, letzte Eilmeldungen. Wer einem Assistenten „Wie sieht es auf der Nordbahn aus?“ fragt, kann ihm diese Adresse geben.'],
        ['Abfahrten laden schneller', 'Der Server fragt die vier Tafeln und bis zu 16 Zugauskünfte jetzt gleichzeitig ab statt nacheinander, aus bis zu 20 Wartezeiten werden zwei. Ist der letzte Stand bis zu drei Minuten alt, kommt er sofort und wird im Hintergrund erneuert. Der n8n-Push wärmt alle zwei Minuten die Abfahrten für Prisdorf vor.'],
        ['Heimatbahnhof in der Statistik', 'Plausible bekommt zu jedem Seitenaufruf den gewählten Heimatbahnhof als Eigenschaft „heimat“ mit, ohne Cookies und ohne Personenbezug. So ist sichtbar, aus welchen Orten die Pendler kommen. Wer nie einen Bahnhof gewählt hat, zählt als „Prisdorf (Vorgabe)“.'],
        ['Archiv für Eilmeldungen und Störungen', 'Die Startseite zeigt nur die jüngsten Einträge. Unter „Alle Eilmeldungen“ und „Alle Störungen“ gibt es jetzt je eine Seite mit der kompletten Historie, nach Tagen gegliedert. Laufende Störungen sind dort als „aktiv“ markiert. Beide Seiten stehen auch im Footer und in der Sitemap.'],
        ['Heute auf einen Blick', 'In den Listen „Letzte Eilmeldungen“ und „Letzte Störungen“ steht statt des Datums „Heute“ oder „Gestern“. Heutige Einträge sind voll ausgezeichnet, ältere treten zurück, damit Veraltetes sofort erkennbar ist. Eilmeldungen mit Pendler-Titel zeigen darunter auch den Pendler-Text.'],
        ['Benachrichtigungen auch bei geschlossener Seite', 'Web Push mit Service Worker: Wer die Glocke einschaltet, bekommt neue Störungen (mit Pendler-Text), Entwarnungen, neue Eilmeldungen und Zugausfälle am eigenen Bahnhof aufs Gerät, auch wenn die Seite zu ist. Pendelzeiten gelten weiter. Auf dem iPhone zuerst zum Home-Bildschirm hinzufügen.'],
    ]],
    ['2026-10-15', [
        ['Störungsstatistik', 'Neue Seite mit Kennzahlen aus dem Logbuch: Störungen und Gesamtdauer der letzten 30 Tage, Verlauf über acht Kalenderwochen und die häufigsten Ursachen. Verlinkt im Footer.'],
        ['Impressum und Datenschutz', 'Eigene Seite mit Anbieterkennzeichnung und Datenschutzerklärung (Hosting, Plausible, lokale Einstellungen, Benachrichtigungen). Die Kontaktangaben kommen aus der Konfiguration.'],
        ['Sicherheit und Suchmaschinen', 'Security-Header (HSTS, Content-Security-Policy, nosniff, Referrer-Policy, Permissions-Policy). Titel und Beschreibung nennen die Bahnhöfe, Strukturdaten beschreiben die Website, Verifizierung für Search Console und Bing per Konfiguration.'],
        ['Eilmeldungen mit Pendler-Titel', 'Der Eilmeldungs-Workflow lässt sich von der KI einen sprechenden Titel schreiben, zum Beispiel welcher Zug wann ausfällt statt nur „Zugausfall RB60“. Die Seite zeigt diesen Titel, der Nordbahn-Titel steht im Tooltip. Meldungen zu kurzfristigem Personalausfall landen nur noch auf der Seite, nicht mehr per Telegram.'],
        ['Abgelaufen ist nicht aufgehoben', 'strecken-info.de meldet eine Störung als „abgelaufen“, sobald ihr gemeldetes Ende vorbei ist, auch wenn sie weiter andauert. Die Workflows nahmen das bisher als erledigt. Jetzt zählt nur noch „geschlossen“; eine abgelaufene Störung bleibt aktiv und zeigt „gemeldetes Ende überschritten, dauert an“.'],
        ['Letzte Störungen', 'Unter den Eilmeldungen steht eine Liste der zuletzt aufgehobenen Infrastruktur-Störungen der letzten sieben Tage: Beginn, Ende, Dauer und der Pendler-Text. Bisher verschwand eine Störung mit ihrer Aufhebung sofort von der Seite.'],
        ['Eigenes Projekt', 'Der Monitor hat ein eigenes Repository mit eigenem Deploy und läuft unabhängig von bensch777.de. Adresse und Analytics stehen in der Konfiguration, nicht mehr im Code.'],
        ['Automatische Tests für die Abfahrten', 'Alle Fälle der Abfahrtstafel sind jetzt als Tests festgehalten: Normalbetrieb, Verspätung, Gleiswechsel, Ausfall mit Ersatz ab Altona, Umleitung, Dammtor, Teilausfälle in beiden Richtungen, Heimatbahnhof-Filter und Ausfälle der Datenquelle. Sie laufen bei jeder Änderung auf GitHub, bevor etwas live geht.'],
        ['„Erst ab Pinneberg“ wie „nur bis Pinneberg“', 'Startet ein Zug der Rückrichtung erst unterwegs, ist die Zeile kein Ausfall mehr, sondern die Abfahrt dort: orangener Punkt, Chip „erst ab Pinneberg“, Abfahrtszeit und Countdown ab Pinneberg, dazu die Hamburger Planzeit als Hinweis. Auch das Gleis ist das in Pinneberg, nicht mehr das am Hbf. Die Zeile bleibt stehen, bis der Zug in Pinneberg abfährt.'],
        ['Nordbahn-Hinweis zum Zuglauf ausgewertet', 'Die Zugauskunft markiert den letzten Halt eines Zuges nicht als Ausfall, auch wenn er laut Hinweis entfällt. Darum zählt jetzt auch der Nordbahn-Text „hält heute nicht in …“. Meldet die Nordbahn „hält nicht in Pinneberg“, der Zug kommt dort laut Prognose aber noch an und wendet, gilt Pinneberg als Endhalt („nur bis Pinneberg“). Fährt ein Zug ab dem Heimatbahnhof wirklich keinen Halt mehr an, steht er als Ausfall mit dem Nordbahn-Hinweis in der Liste.'],
        ['Teilausfälle auch bei unauffälligen Zügen', 'Der Stationsmonitor meldet einen Zug, der heute nur bis Pinneberg fährt, am Heimatbahnhof oft als ganz normale Abfahrt. Darum wird jetzt für jeden gelisteten Zug der Zuglauf geprüft, nicht mehr nur bei Ausfall, Verspätung oder Zuglaufhinweis.'],
        ['Teilausfälle erkannt', 'Endet ein Zug vorzeitig (z. B. in Pinneberg, weil die Hamburger Halte entfallen), steht das als „nur bis Pinneberg“ in der Zeile. Beginnt ein Zug ab Hamburg erst unterwegs, zeigt die Ausfallzeile „Fährt erst ab Pinneberg um …“. Die Fahrplankarte zählt solche Züge mit.'],
        ['Störungskarte kompakter', 'Liegt der Pendler-Text vor, zeigt die Störung nur noch Titel, Text und Zeitraum. Die technischen Details erscheinen nur, solange der Text fehlt.'],
        ['Verlinkte Eilmeldungen erkennbar', 'Meldungen, die noch auf nordbahn.de stehen, sind blau und unterstrichen; ältere aus der Historie bleiben Text.'],
        ['Ortsfehler im Pendler-Text behoben', 'Die Kürzel der Bahn (etwa AE für Hamburg-Eidelstedt) werden jetzt anhand einer Liste für den ganzen Korridor übersetzt. Störungen werden mit Status in der Datenbank behalten statt gelöscht.'],
    ]],
    ['2026-10-14', [
        ['Pendler-Text bei Störungen', 'Infrastrukturstörungen erscheinen in Alltagssprache statt im Bahnjargon („ggl mit zs 6“). Der Text kommt aus dem Alert-Workflow, der auch die Telegram-Kanäle versorgt.'],
        ['Farbige Chips für Start und Ziel', '„ab Hbf“ und „nach Hbf“ blau, „ab Altona“ und „nach Altona“ orange, „ab Dammtor“ blau umrandet. Beide Tafeln lesen sich gleich.'],
        ['Störungen östlich vom Hbf ausgeblendet', 'Eine Weichenstörung bei Schwarzenbek tauchte als Nordbahn-Störung auf. Der räumliche Filter schneidet jetzt alles östlich vom Hbf ab.'],
        ['Footer neu', 'Eigener Streifen mit Projekt, Kanälen (Telegram, X) und Links. Die Telegram-Icons sind aus dem Kopf verschwunden.'],
        ['Überschrift „Aktuelle Lage“', 'Der Seitenname stand doppelt, jetzt sagt die Überschrift, was darunter kommt.'],
        ['FAQ auf eigener Seite', 'Häufige Fragen unter /faq/, nur aus dem Footer verlinkt. Die zwischenzeitlichen Bahnhofsseiten sind wieder weg, sie waren nur doppelter Inhalt.'],
        ['Handy-Ansicht kompakter', 'Titel, Vorspann und Lagekarten nehmen weniger Platz ein, die ersten Züge sind ohne Scrollen sichtbar.'],
    ]],
    ['2026-10-13', [
        ['Suchmaschinen und Vorschauen', 'Lage, Störungen und Eilmeldungen stehen direkt im HTML, Vorschaubild je Lage für Telegram und WhatsApp, Sitemap und robots.txt, Weiterleitung von bensch777.de/nordbahn/ auf die Subdomain.'],
        ['Benachrichtigungen nur zu Pendelzeiten', 'Die Glocke öffnet ein Menü: an/aus und wahlweise nur Mo bis Fr, 6 bis 9 und 16 bis 19 Uhr.'],
        ['Dammtor', 'Züge ab Hbf zeigen ihren Halt am Dammtor mit Zeit und Gleis. Ist der Zug am Hbf schon weg, steht er als eigene Zeile „ab Dammtor“.'],
        ['Ausfälle bleiben kurz stehen', 'Ein ausgefallener Zug bleibt fünf Minuten nach seiner Zeit sichtbar, damit der Bezug zum Ersatzzug erkennbar bleibt.'],
        ['Mein Bahnhof', 'Der eigene Bahnhof ist wählbar, von Pinneberg bis Itzehoe und Wrist, und bleibt im Browser gespeichert. Die Rückrichtung zeigt nur Züge, die dort halten.'],
        ['Eine Standangabe', 'Statt zwei Zeitstempeln steht oben „Aktualisiert vor x Min.“ für die ganze Seite.'],
        ['Refresh-Button und schnellere Daten', 'Button im Kopf zum sofortigen Nachladen. Störungen und Eilmeldungen kommen alle zwei statt fünf Minuten, die Seite fragt jede Minute nach.'],
        ['Leichte Störungen orange', 'Stuft strecken-info eine Störung als leicht ein, bleibt die Karte orange statt rot.'],
        ['Gleiswechsel', 'Das heutige Gleis wird orange hervorgehoben, das Plangleis steht durchgestrichen daneben. Vorher stand bei einem Wechsel das falsche Gleis.'],
        ['Ersatzzug ab Altona als eigene Zeile', 'Fällt ein Zug ab Hbf aus und startet stattdessen ab Altona, steht er zusätzlich als eigene Zeile mit Hinweis „Ersatz für den …“.'],
        ['Eilmeldungen als schlichte Liste', 'Zeit und Titel, ohne die doppelten Linien-Tags.'],
        ['Drei Lagekarten', 'Infrastruktur (strecken-info), akute Eilmeldungen (jünger als vier Stunden) und Fahrplan. Die Fahrplankarte stuft nach dem Anteil betroffener Züge ein: bis 25 % beeinträchtigt, darüber gestört.'],
        ['Startbahnhof der Rückfahrt', 'Jede Zeile der Rückrichtung zeigt, ob der Zug ab Hbf oder ab Altona fährt.'],
        ['Ursachen, Countdown, Benachrichtigungen', 'Verspätungs- und Ausfallgründe aus der Zugauskunft, „in 7 Min“ je Zug, der nächste fahrbare Zug ist markiert, Tab-Markierung und Systembenachrichtigungen bei Ausfällen und Störungen, Installation als Webapp.'],
        ['Wagenzahl und automatisches Nachladen', 'Anzahl der Wagen je Zug, Eilmeldungen älter als drei Tage ausgeblendet, Seite lädt alle zwei Minuten nach und stündlich komplett neu.'],
        ['Start', 'Nordbahn Monitor für RB61 und RB71: Störungen von strecken-info.de, Abfahrten vom Stationsmonitor der Nordbahn, Eilmeldungen von nordbahn.de.'],
    ]],
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Was ist neu – Nordbahn Monitor',
    'url' => SITE_URL . '/changelog/',
    'inLanguage' => 'de',
];
$title = 'Was ist neu – Nordbahn Monitor';
$description = 'Alle Änderungen am Nordbahn Monitor nach Datum: neue Funktionen, Korrekturen und Anpassungen an Lage, Abfahrten und Eilmeldungen.';

function nb_datum($iso)
{
    $dt = DateTime::createFromFormat('Y-m-d', $iso);

    return $dt ? $dt->format('d.m.Y') : $iso;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/changelog/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Nordbahn Monitor">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/changelog/">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= SITE_URL ?>/og/info.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<link rel="icon" type="image/svg+xml" href="<?= $root ?>favicon.svg">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body>

<header class="site-head">
	<div class="wrap site-head__row">
		<a class="site-head__brand" href="<?= $root ?>">Nordbahn Monitor <span class="site-head__domain"><?= htmlspecialchars(nordbahn_site_host(), ENT_QUOTES, 'UTF-8') ?></span></a>
		<nav class="site-head__tools" aria-label="Zur&uuml;ck">
			<a class="tool" href="<?= $root ?>">Zur Lage</a>
		</nav>
	</div>
</header>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>Was ist neu</h1>
			<p class="page-head__lead">Alle &Auml;nderungen am Nordbahn Monitor, neueste zuerst.</p>
		</div>
	</div>

	<section class="section changelog" aria-label="&Auml;nderungen nach Datum">
<?php foreach ($changelog as $tag): ?>
		<h2 class="changelog__date"><?= nb_e(nb_datum($tag[0])) ?></h2>
		<ul class="card changelog__list">
<?php foreach ($tag[1] as $e): ?>
			<li><strong><?= nb_e($e[0]) ?></strong> <span><?= nb_e($e[1]) ?></span></li>
<?php endforeach; ?>
		</ul>
<?php endforeach; ?>
	</section>
</main>

<?php $current = 'changelog'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
