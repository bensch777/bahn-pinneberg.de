<?php
/**
 * Nordbahn Monitor: Statusseite zu den Linien RB61
 * und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist, gedacht fuer
 * Pendler entlang der Strecke.
 *
 * Auf einen Blick: aktive Stoerungen laut strecken-info.de, die naechsten
 * Abfahrten vom eigenen Bahnhof in die Stadt und ab Hamburg zurueck (der
 * Bahnhof ist waehlbar und bleibt im Browser gespeichert), und die letzten
 * Eilmeldungen der Nordbahn.
 *
 * Das Repo-Wurzelverzeichnis ist das Web-Root; alle Links sind relativ,
 * damit die Seite auch unter einem anderen Hostnamen laeuft. Die FAQ liegt
 * unter faq/, der Changelog unter changelog/.
 *
 * Datenfluss: n8n pusht alle 2 Minuten nach api/push (Stoerungen,
 * Eilmeldungen, Historie), die Daten liegen in cache/. Lagekarten,
 * Stoerungen und Eilmeldungen werden hier schon ins HTML gerendert
 * (api/render.php), damit Suchmaschinen und Vorschauen etwas sehen; derselbe
 * Stand steckt als JSON im HTML, dashboard.js uebernimmt die Ansicht und holt
 * spaeter frische Daten von api/data. Die Abfahrten kommen jede Minute live
 * von api/abfahrten (Quelle: der Stationsmonitor der Nordbahn).
 */

require __DIR__ . '/api/store.php';
require __DIR__ . '/api/render.php';
require __DIR__ . '/inc/security.php';
require __DIR__ . '/api/notify.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());

$heimat = NORDBAHN_HEIMAT_DEFAULT;
$root = './';
nb_root($root);
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
$title = 'Nordbahn Monitor – Störungen und Abfahrten RB61/RB71 ab Pinneberg, Elmshorn, Itzehoe und Wrist';
$description = $stoerungSatz . ' Dazu die nächsten Züge ab Pinneberg, Prisdorf, Tornesch, Elmshorn, Itzehoe oder Wrist nach Hamburg und zurück sowie die letzten Eilmeldungen zu RB61 und RB71. Live, alle zwei Minuten aktualisiert.';
$lead = 'Störungen heute, nächste Züge und Eilmeldungen der RB61 und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist.';

$author = ['@type' => 'Person', 'name' => 'Benjamin Neske', 'url' => 'https://bensch777.de/'];
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            '@id' => $pageUrl . '#website',
            'name' => 'Nordbahn Monitor',
            'alternateName' => nordbahn_site_host(),
            'url' => $pageUrl,
            'inLanguage' => 'de',
            'publisher' => $author,
        ],
        [
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
            'author' => $author,
            'isPartOf' => ['@id' => $pageUrl . '#website'],
        ],
    ],
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
<?php require __DIR__ . '/inc/verification.php'; ?>
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
<link rel="alternate" type="text/markdown" href="<?= $root ?>lage.md" title="Aktuelle Lage als Text">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/inc/analytics.php'; ?>
</head>
<body data-push-key="<?= nb_e(nb_push_public_key()) ?>">

<header class="site-head">
	<div class="wrap site-head__row">
		<a class="site-head__brand" href="<?= $root ?>">Nordbahn Monitor <span class="site-head__domain"><?= htmlspecialchars(nordbahn_site_host(), ENT_QUOTES, 'UTF-8') ?></span></a>
		<nav class="site-head__tools" aria-label="Aktualisieren und Benachrichtigungen">
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
					<p class="menu__hint" id="notify-push-hint" hidden></p>
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
			<h1>Aktuelle Lage</h1>
			<p class="page-head__lead"><?= nb_e($lead) ?></p>
		</div>
		<p class="freshness" id="freshness" aria-live="polite"></p>
	</div>

	<section id="status" class="section" aria-label="Lagekarten">
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
			<a class="section__more" href="<?= $root ?>eilmeldungen/">Alle Eilmeldungen</a>
		</div>
		<ol class="feed card" id="feed" data-limit="5" data-max-age-days="1">
			<?= nb_render_feed($items, 5, 1) ?>

		</ol>
	</section>

	<section id="verlauf" class="section" aria-labelledby="verlauf-h">
		<div class="section__head">
			<h2 id="verlauf-h" class="section__title">Letzte Infrastrukturst&ouml;rungen</h2>
			<a class="section__more" href="<?= $root ?>stoerungen/">Alle St&ouml;rungen</a>
		</div>
		<ol class="feed card" id="verlauf-feed" data-limit="5" data-max-age-days="1">
			<?= nb_render_verlauf(nb_verlauf_items($data), 5, 1, null, count($data['stoerungen'] ?? [])) ?>

		</ol>
	</section>

	<noscript><p class="notice">Ohne JavaScript bleibt die Seite auf diesem Stand stehen. Die Rohdaten gibt es unter <a href="<?= $root ?>api/data">api/data</a>.</p></noscript>
</main>

<?php $current = 'lage'; require __DIR__ . '/inc/footer.php'; ?>

<script id="nordbahn-data" type="application/json"><?= $json ?></script>
<script src="<?= $root ?>dashboard.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
