<?php
/**
 * Nordbahn Monitor (nordbahn.bensch777.de): Statusseite zu den Linien RB61
 * und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist, gedacht fuer
 * Pendler aus Prisdorf.
 *
 * Auf einen Blick: aktive Stoerungen laut strecken-info.de, die naechsten
 * Abfahrten ab Prisdorf in die Stadt und ab Hamburg zurueck, und die letzten
 * Eilmeldungen der Nordbahn.
 *
 * Dieser Ordner ist das Wurzelverzeichnis der Subdomain und zugleich als
 * /nordbahn/ auf der Hauptseite erreichbar, deshalb sind alle Links relativ.
 *
 * Datenfluss: n8n pusht alle 5 Minuten nach api/push (Stoerungen,
 * Eilmeldungen, Historie), die Daten liegen in cache/nordbahn/. Der Stand
 * wird hier als JSON ins HTML eingebettet, damit die Seite ohne zweiten
 * Request rendert; dashboard.js baut daraus die Ansicht und holt sich
 * spaeter frische Daten von api/data. Die Abfahrten kommen jede Minute live
 * von api/abfahrten (Quelle: der Stationsmonitor der Nordbahn).
 */

require __DIR__ . '/api/store.php';

const SITE_URL = 'https://nordbahn.bensch777.de';

$data = nordbahn_dataset();

// JSON_HEX_TAG sorgt dafuer, dass ein "</script>" in einem Meldungstext den
// Skriptblock nicht beenden kann.
$json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($json === false) {
    $json = '{"received_at":null,"stoerungen":[],"eilmeldungen_live":[],"eilmeldungen":[],"stoerungen_verlauf":[]}';
}

$aktiv = count($data['stoerungen']);
$description = $aktiv > 0
    ? $aktiv . ' aktive St&ouml;rung' . ($aktiv === 1 ? '' : 'en') . ' auf der Nordbahn-Strecke Hamburg &ndash; Elmshorn &ndash; Itzehoe/Wrist. Dazu die n&auml;chsten Z&uuml;ge ab Prisdorf nach Hamburg und zur&uuml;ck sowie die letzten Eilmeldungen zu RB61 und RB71.'
    : 'St&ouml;rungen, n&auml;chste Z&uuml;ge zwischen Prisdorf und Hamburg und Eilmeldungen der Nordbahn-Linien RB61 und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist auf einen Blick.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nordbahn Monitor &ndash; St&ouml;rungen, Abfahrten und Eilmeldungen RB61/RB71</title>
<meta name="description" content="<?= $description ?>">
<link rel="canonical" href="<?= SITE_URL ?>/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">

<meta property="og:type" content="website">
<meta property="og:site_name" content="bensch777">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/">
<meta property="og:title" content="Nordbahn Monitor &ndash; St&ouml;rungen, Abfahrten und Eilmeldungen RB61/RB71">
<meta property="og:description" content="<?= $description ?>">

<link rel="icon" id="favicon" type="image/svg+xml" href="favicon.svg">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Nordbahn">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="stylesheet" href="dashboard.css">
<script defer src="https://bensch777.de/js/pa.js" data-domain="bensch777.de"></script>
</head>
<body>

<header class="site-head">
	<div class="wrap site-head__row">
		<a class="site-head__brand" href="./">Nordbahn Monitor</a>
		<nav class="site-head__tools" aria-label="Kan&auml;le und Benachrichtigungen">
			<a class="tool" href="https://t.me/NordbahnEilmeldungen" rel="noopener" title="Telegram: Nordbahn Eilmeldungen">
				<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>
				<span>Eilmeldungen</span>
			</a>
			<a class="tool" href="https://t.me/prisdorfpendler" rel="noopener" title="Telegram: Prisdorf Pendler, alle Bahn-Updates">
				<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>
				<span>Pendler</span>
			</a>
			<button type="button" class="tool tool--toggle" id="notify-toggle" aria-pressed="false" title="Bei Ausf&auml;llen benachrichtigen">
				<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2Zm7-6.3V11c0-3.3-2-6.1-5-6.8V3.5a2 2 0 1 0-4 0v.7C7 4.9 5 7.7 5 11v4.7L3 17.5V19h18v-1.5l-2-1.8ZM17 16.5H7V11c0-2.8 2.1-5 5-5s5 2.2 5 5v5.5Z"/></svg>
				<span class="visually-hidden" id="notify-label">Bei Ausf&auml;llen benachrichtigen</span>
			</button>
		</nav>
	</div>
</header>

<main class="wrap" id="app">
	<div class="page-head">
		<div>
			<h1>Nordbahn Monitor</h1>
			<p class="page-head__lead">RB61 und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist.</p>
		</div>
		<p class="freshness" id="freshness" aria-live="polite"></p>
	</div>

	<section id="status" class="section" aria-labelledby="status-h">
		<h2 id="status-h" class="visually-hidden">Aktuelle Lage</h2>
	</section>

	<section id="abfahrten" class="section" aria-labelledby="abfahrten-h" data-endpoint="api/abfahrten" data-data-endpoint="api/data">
		<div class="section__head">
			<h2 id="abfahrten-h" class="section__title">N&auml;chste Z&uuml;ge</h2>
			<p class="section__sub" id="abfahrten-sub"></p>
		</div>
		<div class="boards">
			<div class="card board" data-richtung="hin">
				<div class="board__head">
					<h3 class="board__title">Prisdorf &rarr; Hamburg</h3>
					<p class="board__sub">Hbf / Altona</p>
				</div>
				<ol class="board__list" aria-live="polite"></ol>
			</div>
			<div class="card board" data-richtung="rueck">
				<div class="board__head">
					<h3 class="board__title">Hamburg &rarr; Prisdorf</h3>
					<p class="board__sub">ab Hbf und Altona</p>
				</div>
				<ol class="board__list" aria-live="polite"></ol>
			</div>
		</div>
	</section>

	<section id="latest" class="section" aria-labelledby="latest-h">
		<div class="section__head">
			<h2 id="latest-h" class="section__title">Letzte Eilmeldungen</h2>
		</div>
		<ol class="feed" id="feed" data-limit="6" data-max-age-days="3"></ol>
	</section>

	<noscript><p class="notice">Ohne JavaScript bleibt die Seite leer. Die Rohdaten gibt es unter <a href="api/data">api/data</a>.</p></noscript>
</main>

<footer class="site-foot wrap">
	<p>Ein Projekt von <a href="https://bensch777.de/">bensch777.de</a>, keine offizielle Seite der Nordbahn. Quellen: nordbahn.de und strecken-info.de. Zeiten in deutscher Zeit.</p>
</footer>

<script id="nordbahn-data" type="application/json"><?= $json ?></script>
<script src="dashboard.js" defer></script>
</body>
</html>
