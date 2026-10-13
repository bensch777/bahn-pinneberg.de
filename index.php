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

<link rel="stylesheet" href="dashboard.css">
<script defer src="https://bensch777.de/js/pa.js" data-domain="bensch777.de"></script>
</head>
<body>

<header class="site-head">
	<div class="wrap site-head__row">
		<a class="site-head__brand" href="./">Nordbahn Monitor</a>
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
	<p class="site-foot__telegram">Auch auf Telegram: <a href="https://t.me/NordbahnEilmeldungen" rel="noopener">Nordbahn Eilmeldungen</a> und <a href="https://t.me/prisdorfpendler" rel="noopener">Prisdorf Pendler</a> mit allen Bahn-Updates.</p>
	<p>Ein Projekt von <a href="https://bensch777.de/">bensch777.de</a>, keine offizielle Seite der Nordbahn. Quellen: nordbahn.de und strecken-info.de. Zeiten in deutscher Zeit.</p>
</footer>

<script id="nordbahn-data" type="application/json"><?= $json ?></script>
<script src="dashboard.js" defer></script>
</body>
</html>
