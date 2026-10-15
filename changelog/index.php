<?php
/**
 * Changelog des Nordbahn Monitors: alle Aenderungen nach Datum, nur aus dem
 * Footer verlinkt. Neue Eintraege oben anfuegen.
 */

require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/render.php';

const SITE_URL = 'https://nordbahn.bensch777.de';
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$changelog = [
    ['2026-10-15', [
        ['„Erst ab Pinneberg“ wie „nur bis Pinneberg“', 'Startet ein Zug der Rückrichtung erst unterwegs, ist die Zeile kein Ausfall mehr, sondern die Abfahrt dort: orangener Punkt, Chip „erst ab Pinneberg“, Abfahrtszeit und Countdown ab Pinneberg, dazu die Hamburger Planzeit als Hinweis. Die Zeile bleibt stehen, bis der Zug in Pinneberg abfährt.'],
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
<!-- Privacy-friendly analytics by Plausible -->
<script async src="https://plausible.io/js/pa-YR5IRACXIMdFAo0Tud1Lj.js"></script>
<script>
  window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
  plausible.init()
</script>
</head>
<body>

<header class="site-head">
	<div class="wrap site-head__row">
		<a class="site-head__brand" href="<?= $root ?>">Nordbahn Monitor</a>
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
