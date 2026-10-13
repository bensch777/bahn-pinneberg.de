<?php
/**
 * Nordbahn Monitor, Unterseite Statistik: Auswertung der Eilmeldungen und
 * Stoerungen ueber die Zeit (pro Woche, Kategorie, Linie, Wochentag, Uhrzeit).
 *
 * Die Daten kommen wie auf der Startseite aus dem n8n-Push (cache/nordbahn/),
 * siehe ../index.php. Alle Links sind relativ, weil der Ordner darueber das
 * Wurzelverzeichnis von nordbahn.bensch777.de ist. Der Stand wird als JSON eingebettet, dashboard.js
 * rendert die Diagramme.
 */

require __DIR__ . '/../api/store.php';

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

$description = 'Wie oft und warum es bei RB61 und RB71 hakt: Eilmeldungen der Nordbahn seit M&auml;rz 2026 nach Woche, Kategorie, Linie, Wochentag und Uhrzeit, dazu der Verlauf der St&ouml;rungen laut strecken-info.de.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nordbahn Monitor &ndash; Statistik zu St&ouml;rungen und Eilmeldungen RB61/RB71</title>
<meta name="description" content="<?= $description ?>">
<link rel="canonical" href="<?= SITE_URL ?>/statistik/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">

<meta property="og:type" content="website">
<meta property="og:site_name" content="bensch777">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/statistik/">
<meta property="og:title" content="Nordbahn Monitor &ndash; Statistik RB61/RB71">
<meta property="og:description" content="<?= $description ?>">

<link rel="stylesheet" href="../dashboard.css">
<script defer src="https://bensch777.de/js/pa.js" data-domain="bensch777.de"></script>
</head>
<body>

<header class="site-head">
	<div class="wrap site-head__row">
		<a class="site-head__brand" href="../">Nordbahn Monitor</a>
		<nav class="site-head__nav" aria-label="Nordbahn Monitor">
			<a href="../">Status</a>
			<a href="./" aria-current="page">Statistik</a>
		</nav>
	</div>
</header>

<main class="wrap" id="app" data-data-endpoint="../api/data">
	<div class="page-head">
		<div>
			<p class="page-head__kicker"><a href="../">Nordbahn Monitor</a></p>
			<h1>Statistik</h1>
			<p class="page-head__lead">Wie oft und warum es bei RB61 und RB71 hakt. Grundlage sind alle Eilmeldungen der Nordbahn seit M&auml;rz 2026 und der Verlauf der St&ouml;rungen laut strecken-info.de.</p>
		</div>
		<p class="freshness" id="freshness" aria-live="polite"></p>
	</div>

	<section id="kpis" class="section" aria-labelledby="kpis-h">
		<h2 id="kpis-h" class="section__title">Eilmeldungen auf einen Blick</h2>
		<div class="tiles" id="tiles"></div>
	</section>

	<section id="latest" class="section" aria-labelledby="latest-h">
		<div class="section__head">
			<h2 id="latest-h" class="section__title">Letzte Eilmeldungen</h2>
			<p class="section__sub">Von der Nordbahn ver&ouml;ffentlicht, alle 15 Minuten abgeglichen.</p>
		</div>
		<ol class="feed" id="feed"></ol>
	</section>

	<section id="analysis" class="section" aria-labelledby="analysis-h">
		<div class="section__head">
			<h2 id="analysis-h" class="section__title">Auswertung</h2>
			<p class="section__sub" id="analysis-sub"></p>
		</div>
		<div class="filters" role="radiogroup" aria-label="Zeitraum" id="range">
			<button type="button" role="radio" data-range="28" aria-checked="false">4 Wochen</button>
			<button type="button" role="radio" data-range="91" aria-checked="true">12 Wochen</button>
			<button type="button" role="radio" data-range="all" aria-checked="false">Seit Start</button>
		</div>
		<div class="charts" id="charts"></div>
	</section>

	<section id="pipeline" class="section section--compact" aria-labelledby="pipeline-h">
		<h2 id="pipeline-h" class="section__title">Woher die Daten kommen</h2>
		<p class="pipeline__text">Die Eilmeldungen stammen von <a href="https://www.nordbahn.de/reiseplanung/eilmeldungen-sonderfahrplaene/?line-filter=3" rel="noopener">nordbahn.de</a> und werden seit dem 26. M&auml;rz 2026 alle 15 Minuten abgeglichen; Kategorie und Linie sind aus dem Titel abgeleitet. Die St&ouml;rungen liefert <a href="https://strecken-info.de/" rel="noopener">strecken-info.de</a> (DB InfraGO) f&uuml;r den Abschnitt Hamburg&nbsp;&ndash; Elmshorn&nbsp;&ndash; Itzehoe/Wrist; aufgehobene St&ouml;rungen bleiben hier im Verlauf.</p>
	</section>

	<noscript><p class="notice">Ohne JavaScript bleiben die Diagramme leer. Die Rohdaten gibt es unter <a href="../api/data">api/data</a>.</p></noscript>
</main>

<footer class="site-foot wrap">
	<p class="site-foot__telegram">Auch auf Telegram: <a href="https://t.me/NordbahnEilmeldungen" rel="noopener">Nordbahn Eilmeldungen</a> und <a href="https://t.me/prisdorfpendler" rel="noopener">Prisdorf Pendler</a> mit allen Bahn-Updates.</p>
	<p>Ein Projekt von <a href="https://bensch777.de/">bensch777.de</a>, keine offizielle Seite der Nordbahn. Quellen: nordbahn.de und strecken-info.de. Zeiten in deutscher Zeit.</p>
</footer>

<script id="nordbahn-data" type="application/json"><?= $json ?></script>
<script src="../dashboard.js" defer></script>
</body>
</html>
