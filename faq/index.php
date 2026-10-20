<?php
/**
 * Haeufige Fragen zum Nordbahn Monitor. Eigene Seite, nur aus dem Footer
 * verlinkt; die Fragen stehen zusaetzlich als FAQPage-JSON-LD im Head.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$faq = [
    ['Was bedeutet „Nordbahn beeinträchtigt“ oder „Nordbahn gestört“?',
     'Die Karte zählt, wie viele der nächsten Züge in beide Richtungen betroffen sind: Zugausfälle, Umleitungen über Hamburg-Altona und Verspätungen ab zehn Minuten. Bis zu einem Viertel der Züge heißt „beeinträchtigt“, darüber „gestört“.'],
    ['Woher kommen die Daten?',
     'Abfahrten, Gleise und Verspätungen stammen vom Stationsmonitor der Nordbahn, Eilmeldungen von nordbahn.de, Infrastrukturstörungen von strecken-info.de der DB InfraGO. Die S-Bahn S3 und die HVV-Meldungen kommen über die Schnittstelle des HVV (Geofox). Abfahrten und S3 werden jede Minute geladen, Störungen und Eilmeldungen alle zwei Minuten.'],
    ['Was zeigt die Karte „S3 Pinneberg – Hamburg“?',
     'Die S3 ist der Plan B, wenn die RB nur bis Pinneberg fährt oder ausfällt. Die Karte wertet die nächsten S3-Züge der kommenden Stunde ab Pinneberg Richtung Hamburg und ab Hamburg Hbf Richtung Pinneberg aus: Ausfälle und Verspätungen ab fünf Minuten zählen, ab einem Viertel betroffener Züge heißt es „S3 gestört“, darunter „S3 beeinträchtigt“. Eine ungeplante HVV-Meldung zur S3, die gerade gilt, setzt die Karte ebenfalls auf „gestört“. Welcher Stadtbahnhof gilt, Hauptbahnhof, Jungfernstieg oder Altona, lässt sich im S3-Abschnitt wählen und wird im Browser gemerkt. Geplante Meldungen wie Bauarbeiten färben die Karte nicht, sie stehen mit allen HVV-Meldungen zu S3, RB61 und RB71 in der Liste „Letzte HVV-Meldungen“.'],
    ['Welche Linien und Bahnhöfe werden angezeigt?',
     'Die RB61 Hamburg – Itzehoe, die RB71 Hamburg – Wrist und die Verstärkerzüge der RB60. Als eigener Bahnhof lässt sich jeder Halt zwischen Pinneberg und Itzehoe bzw. Wrist wählen, die Auswahl bleibt im Browser gespeichert.'],
    ['Was bedeuten „ab Hbf“, „ab Dammtor“, „ab Altona“ und „ab Pinneberg“?',
     'Züge Richtung Norden starten am Hamburg Hbf und halten drei Minuten später am Dammtor; die Zeile zeigt beides. Fällt ein Zug am Hbf aus, fährt er oft stattdessen ab Hamburg-Altona. Das steht als Hinweis am Ausfall und als eigene, orange markierte Zeile. Nachts beginnen die Züge nach Norden oft erst in Pinneberg; sie stehen dann als „ab Pinneberg“ in der Liste, aus Hamburg kommt man mit der S-Bahn dorthin.'],
    ['Kann ich mich bei Zugausfällen benachrichtigen lassen?',
     'Ja, über die Glocke oben rechts, wahlweise nur zu Pendelzeiten. Gemeldet werden Zugausfälle am eigenen Bahnhof, neue und aufgehobene Störungen sowie Eilmeldungen, per Web Push auch bei geschlossener Seite. Auf dem iPhone geht das nur, wenn die Seite über „Teilen“ zum Home-Bildschirm hinzugefügt und von dort geöffnet wird. Unabhängig davon gibt es die Telegram-Kanäle „Nordbahn Eilmeldungen“ und „Prisdorf Pendler“.'],
    ['Kann ich die Seite als App nutzen?',
     'Ja. Auf dem iPhone über Teilen und „Zum Home-Bildschirm“, auf Android über „App installieren“ im Browsermenü. Die Seite startet dann im Vollbild und aktualisiert sich selbst.'],
    ['Was zählt als „wichtige Meldung“?',
     'Die Seite „Wichtige Meldungen“ filtert nach festen Regeln: Infrastrukturstörungen mit schwerer Einschränkung, mit Ausfällen, Umleitungen, Ersatzverkehr oder Sperrungen als Auswirkung oder mit mindestens zwei Stunden Dauer. Bei den Eilmeldungen der Nordbahn alles zu Ersatzverkehr, Sperrungen, Streik, Bauarbeiten, Unwetter, Stellwerk- oder Signalstörungen sowie Meldungen, die mehrere Züge oder einen Zeitraum betreffen. Einzelne kurzfristige Zugausfälle, etwa wegen Personalausfall, stehen nur im Archiv der Eilmeldungen.'],
    ['Was steht auf der Seite einer einzelnen Störung?',
     'Jede Störung und jede Eilmeldung hat eine eigene Seite, erreichbar über den Titel in den Listen: der Pendler-Text, die Originalmeldung von strecken-info.de, der Verlauf mit jedem neuen Stand, die in dieser Zeit beobachteten Ausfälle und Verspätungen sowie Eilmeldungen und weitere Störungen zur selben Zeit. Die Push-Nachrichten auf Telegram und X verlinken direkt dorthin.'],
    ['Woher kommen die Werte „Letzte 30 Tage: 2× ausgefallen, Ø +3 min“?',
     'Die Seite merkt sich bei jeder Abfrage der Abfahrten je Tag und Zug den letzten Stand vor der Abfahrt: ausgefallen, nur teilweise gefahren, wie viele Minuten verspätet. Nach fünf beobachteten Tagen steht die Bilanz unter der Zeile und auf der Statistikseite je Bahnhof. Beobachtet wird nur, was abgefragt wird: Prisdorf durchgehend, andere Bahnhöfe, solange jemand die Seite mit diesem Bahnhof offen hat. Die Werte sind eine Näherung aus der Prognose des Stationsmonitors, keine amtliche Pünktlichkeitsstatistik.'],
    ['Gibt es die Daten auch für Programme oder KI-Assistenten?',
     'Ja. Unter /lage.md steht die aktuelle Lage als Text (Markdown), unter /llms.txt eine Beschreibung der Seite und der Datenfelder, und /api/data sowie /api/abfahrten?bhf=APD liefern alles als JSON. Alles öffentlich und ohne Schlüssel, bitte nicht häufiger als einmal pro Minute abrufen.'],
    ['Ist das eine offizielle Seite der Nordbahn?',
     'Nein. Der Nordbahn Monitor ist ein privates Projekt ohne Verbindung zur NBE Nordbahn Eisenbahngesellschaft, Betreiber siehe Impressum. Verbindliche Auskünfte gibt es auf nordbahn.de.'],
];
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(function ($qa) {
        return ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]];
    }, $faq),
];
$title = 'Häufige Fragen – Nordbahn Monitor';
$description = 'Wie der Nordbahn Monitor Störungen, Abfahrten und Eilmeldungen der RB61 und RB71 ermittelt, woher die Daten kommen und wie Benachrichtigungen funktionieren.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/faq/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Nordbahn Monitor">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/faq/">
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
			<h1>H&auml;ufige Fragen</h1>
			<p class="page-head__lead">Wie der Nordbahn Monitor funktioniert und woher die Daten kommen.</p>
		</div>
	</div>

	<section class="section" aria-label="Fragen und Antworten">
		<div class="card faq">
<?php foreach ($faq as $qa): ?>
			<details>
				<summary><?= nb_e($qa[0]) ?></summary>
				<p><?= nb_e($qa[1]) ?></p>
			</details>
<?php endforeach; ?>
		</div>
	</section>
</main>

<?php $current = 'faq'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
