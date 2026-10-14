<?php
/**
 * Nordbahn Monitor (nordbahn.bensch777.de): Statusseite zu den Linien RB61
 * und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist, gedacht fuer
 * Pendler entlang der Strecke.
 *
 * Auf einen Blick: aktive Stoerungen laut strecken-info.de, die naechsten
 * Abfahrten vom eigenen Bahnhof in die Stadt und ab Hamburg zurueck (der
 * Bahnhof ist waehlbar und bleibt im Browser gespeichert), und die letzten
 * Eilmeldungen der Nordbahn.
 *
 * Der Ordner ist das Wurzelverzeichnis der Subdomain und zugleich als
 * /nordbahn/ auf der Hauptseite erreichbar, deshalb sind alle Links relativ.
 * Die FAQ liegt unter faq/.
 *
 * Datenfluss: n8n pusht alle 2 Minuten nach api/push (Stoerungen,
 * Eilmeldungen, Historie), die Daten liegen in cache/nordbahn/. Lagekarten,
 * Stoerungen und Eilmeldungen werden hier schon ins HTML gerendert
 * (api/render.php), damit Suchmaschinen und Vorschauen etwas sehen; derselbe
 * Stand steckt als JSON im HTML, dashboard.js uebernimmt die Ansicht und holt
 * spaeter frische Daten von api/data. Die Abfahrten kommen jede Minute live
 * von api/abfahrten (Quelle: der Stationsmonitor der Nordbahn).
 */

require __DIR__ . '/api/store.php';
require __DIR__ . '/api/render.php';

const SITE_URL = 'https://nordbahn.bensch777.de';

$heimat = NORDBAHN_HEIMAT_DEFAULT;
$root = './';
$bahnhof = NORDBAHN_BAHNHOEFE[$heimat];
$pageUrl = SITE_URL . '/';
$rueckTitel = 'Hamburg → Wrist / Itzehoe';

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
$items = nb_items($data);
$cards = [
    'infra'   => nb_status_infra($data),
    'eil'     => nb_status_eil($data, $items),
    'betrieb' => nb_status_betrieb(nb_read_abfahrten($heimat)),
];
$ogImage = SITE_URL . '/og/' . nb_overall_level($cards) . '.png';

// Versionsstempel an Script und Stylesheet, damit Browser nach einem Deploy nicht
// mit altem JavaScript gegen die neue API laufen.
$assetVersion = max((int) @filemtime(__DIR__ . '/dashboard.js'), (int) @filemtime(__DIR__ . '/dashboard.css'));

$stoerungSatz = $aktiv > 0
    ? $aktiv . ' aktive Störung' . ($aktiv === 1 ? '' : 'en') . ' auf der Nordbahn-Strecke Hamburg – Elmshorn – Itzehoe/Wrist.'
    : 'Störungen heute auf der Nordbahn-Strecke Hamburg – Elmshorn – Itzehoe/Wrist.';
$title = 'Nordbahn Monitor – Störungen, Abfahrten und Eilmeldungen RB61/RB71 live';
$description = $stoerungSatz . ' Dazu die nächsten Züge vom eigenen Bahnhof nach Hamburg und zurück sowie die letzten Eilmeldungen zu RB61 und RB71. Live, alle zwei Minuten aktualisiert.';
$lead = 'Störungen heute, nächste Züge und Eilmeldungen der RB61 und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist.';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebApplication',
    'name' => 'Nordbahn Monitor',
    'url' => $pageUrl,
    'description' => $description,
    'applicationCategory' => 'TravelApplication',
    'operatingSystem' => 'Any',
    'browserRequirements' => 'Requires JavaScript',
    'inLanguage' => 'de',
    'isAccessibleForFree' => true,
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
    'author' => ['@type' => 'Person', 'name' => 'Benjamin Neske', 'url' => 'https://bensch777.de/'],
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= nb_e($pageUrl) ?>">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Nordbahn Monitor">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= nb_e($pageUrl) ?>">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= nb_e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Aktuelle Lage auf der Nordbahn-Strecke">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= nb_e($title) ?>">
<meta name="twitter:description" content="<?= nb_e($description) ?>">
<meta name="twitter:image" content="<?= nb_e($ogImage) ?>">

<link rel="icon" id="favicon" type="image/svg+xml" href="<?= $root ?>favicon.svg">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png">
<link rel="manifest" href="<?= $root ?>manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Nordbahn">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
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
		<nav class="site-head__tools" aria-label="Kan&auml;le und Benachrichtigungen">
			<a class="tool" href="https://t.me/NordbahnEilmeldungen" rel="noopener" title="Telegram: Nordbahn Eilmeldungen">
				<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>
				<span>Eilmeldungen</span>
			</a>
			<a class="tool" href="https://t.me/prisdorfpendler" rel="noopener" title="Telegram: Prisdorf Pendler, alle Bahn-Updates">
				<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 3.3 2.9 10.6c-1.1.4-1.1 1.1-.2 1.4l4.7 1.5 1.8 5.6c.2.6.1.9.8.9.5 0 .7-.2 1-.5l2.3-2.3 4.8 3.6c.9.5 1.5.2 1.7-.8l3.2-15c.3-1.3-.5-1.9-1.4-1.7ZM8.4 13.1l9.6-6c.5-.3.9-.1.5.2l-7.9 7.2-.3 3.3-1.9-4.7Z"/></svg>
				<span>Pendler</span>
			</a>
			<button type="button" class="tool tool--refresh" id="refresh-button" title="Jetzt aktualisieren">
				<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.65 6.35A7.96 7.96 0 0 0 12 4a8 8 0 1 0 7.74 10h-2.08A6 6 0 1 1 12 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35Z"/></svg>
				<span class="visually-hidden">Jetzt aktualisieren</span>
			</button>
			<div class="tool-menu" id="notify-wrap">
				<button type="button" class="tool tool--toggle" id="notify-toggle" aria-pressed="false" aria-expanded="false" aria-controls="notify-menu" title="Benachrichtigungen">
					<svg class="tool__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.5 2.5 0 0 0 2.4-2h-4.8a2.5 2.5 0 0 0 2.4 2Zm7-6.3V11c0-3.3-2-6.1-5-6.8V3.5a2 2 0 1 0-4 0v.7C7 4.9 5 7.7 5 11v4.7L3 17.5V19h18v-1.5l-2-1.8ZM17 16.5H7V11c0-2.8 2.1-5 5-5s5 2.2 5 5v5.5Z"/></svg>
					<span class="visually-hidden" id="notify-label">Benachrichtigungen</span>
				</button>
				<div class="menu" id="notify-menu" hidden>
					<label class="menu__row"><input type="checkbox" id="notify-on"> <span>Bei Ausf&auml;llen und St&ouml;rungen benachrichtigen</span></label>
					<p class="menu__hint" id="notify-hint" hidden></p>
					<fieldset class="menu__group">
						<legend>Wann</legend>
						<label class="menu__row"><input type="radio" name="notify-zeit" value="pendel"> <span>Nur zu Pendelzeiten<small>Mo&ndash;Fr, 6&ndash;9 und 16&ndash;19 Uhr</small></span></label>
						<label class="menu__row"><input type="radio" name="notify-zeit" value="immer"> <span>Immer</span></label>
					</fieldset>
				</div>
			</div>
		</nav>
	</div>
</header>

<main class="wrap" id="app">
	<div class="page-head">
		<div>
			<h1>Nordbahn Monitor</h1>
			<p class="page-head__lead"><?= nb_e($lead) ?></p>
		</div>
		<p class="freshness" id="freshness" aria-live="polite"></p>
	</div>

	<section id="status" class="section" aria-labelledby="status-h">
		<h2 id="status-h" class="visually-hidden">Aktuelle Lage</h2>
		<div class="status-grid">
			<?= nb_render_status_card('status-infra', $cards['infra']) ?>
			<?= nb_render_status_card('status-eil', $cards['eil']) ?>
			<?= nb_render_status_card('status-betrieb', $cards['betrieb']) ?>
		</div>
		<ul class="incidents" id="incidents"<?= $aktiv ? '' : ' hidden' ?>>
<?php foreach ($data['stoerungen'] as $st): ?>
			<?= nb_render_incident($st) ?>

<?php endforeach; ?>
		</ul>
	</section>

	<section id="abfahrten" class="section" aria-labelledby="abfahrten-h" data-root="<?= $root ?>" data-endpoint="<?= $root ?>api/abfahrten" data-data-endpoint="<?= $root ?>api/data" data-heimat-default="<?= $heimat ?>">
		<div class="section__head">
			<h2 id="abfahrten-h" class="section__title">N&auml;chste Z&uuml;ge</h2>
			<div class="section__tools">
				<label class="heimat">
					<span class="heimat__label">Mein Bahnhof</span>
					<select id="heimat" class="heimat__select">
<?php foreach (NORDBAHN_BAHNHOEFE as $id => $bhf): ?>
						<option value="<?= $id ?>" data-ast="<?= $bhf['ast'] ?>"<?= $id === $heimat ? ' selected' : '' ?>><?= nb_e($bhf['name']) ?></option>
<?php endforeach; ?>
					</select>
				</label>
				<p class="section__sub" id="abfahrten-sub"></p>
			</div>
		</div>
		<div class="boards">
			<div class="card board" data-richtung="hin">
				<div class="board__head">
					<h3 class="board__title" id="board-title-hin"><?= nb_e($bahnhof['name']) ?> &rarr; Hamburg</h3>
				</div>
				<ol class="board__list" aria-live="polite"></ol>
			</div>
			<div class="card board" data-richtung="rueck">
				<div class="board__head">
					<h3 class="board__title" id="board-title-rueck"><?= nb_e($rueckTitel) ?></h3>
				</div>
				<ol class="board__list" aria-live="polite"></ol>
			</div>
		</div>
	</section>

	<section id="latest" class="section" aria-labelledby="latest-h">
		<div class="section__head">
			<h2 id="latest-h" class="section__title">Letzte Eilmeldungen</h2>
		</div>
		<ol class="feed card" id="feed" data-limit="6" data-max-age-days="3">
			<?= nb_render_feed($items, 6, 3) ?>

		</ol>
	</section>

	<noscript><p class="notice">Ohne JavaScript bleibt die Seite auf diesem Stand stehen. Die Rohdaten gibt es unter <a href="<?= $root ?>api/data">api/data</a>.</p></noscript>
</main>

<footer class="site-foot wrap">
	<p>Ein Projekt von <a href="https://bensch777.de/">bensch777.de</a>, keine offizielle Seite der Nordbahn. Quellen: nordbahn.de und strecken-info.de.</p>
	<p class="site-foot__links">Telegram: <a href="https://t.me/NordbahnEilmeldungen" rel="noopener">Nordbahn Eilmeldungen</a> &middot; <a href="https://t.me/prisdorfpendler" rel="noopener">Prisdorf Pendler</a> &middot; <a href="<?= $root ?>faq/">H&auml;ufige Fragen</a></p>
</footer>

<script id="nordbahn-data" type="application/json"><?= $json ?></script>
<script src="<?= $root ?>dashboard.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
