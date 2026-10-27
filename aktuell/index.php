<?php
/**
 * Aktive Meldungen (/aktuell/): was gerade gilt, ohne Wichtig-Filter und ohne
 * Wegklicken. Laufende Infrastrukturstoerungen als Karten, ein gueltiger
 * Baufahrplan, darunter die aktuellen Meldungen wie auf der Startseite
 * (nb_aktuelle_meldungen: Eilmeldungen der letzten vier Stunden, gerade
 * aufgehobene Stoerungen, gueltige HVV-Meldungen). Ziel der Lagekarten
 * "Infrastruktur" und "Eilmeldungen". Rein server-seitig.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require_once __DIR__ . '/../api/geofox.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
nb_root($root);
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$data = nordbahn_dataset();
$now = time();
$teile = nb_stoerungen_teilen($data['stoerungen'] ?? []);
$stoerungen = $teile['stoerungen'];
$hinweise = $teile['hinweise'];
$bau = nb_baufahrplan($data, $now);
$gfAktiv = gf_aktiv();
$gf = $gfAktiv ? nb_read_geofox() : null;
$meldungen = nb_aktuelle_meldungen($data, $gfAktiv ? ($gf['s3']['meldungen'] ?? []) : null, $now);
$stand = !empty($data['received_at']) ? nb_ts($data['received_at']) : null;
$ruhig = !$stoerungen && !$hinweise && !$bau && !$meldungen;

$pageUrl = SITE_URL . '/aktuell/';
$title = 'Aktive Meldungen zur Nordbahn RB60/RB61/RB71 – was gerade gilt';
$description = 'Was gerade auf den Nordbahn-Linien zwischen Hamburg, Pinneberg, Elmshorn, Itzehoe und Wrist gilt: laufende Infrastrukturstörungen, Baufahrplan, Eilmeldungen der letzten vier Stunden, gerade aufgehobene Störungen'
    . ($gfAktiv ? ' und gültige HVV-Meldungen' : '') . '. Ohne Filter, alle Meldungen.';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $title,
    'description' => $description,
    'url' => $pageUrl,
    'inLanguage' => 'de',
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Bahn-Pinneberg.de', 'url' => SITE_URL . '/'],
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
<meta property="og:site_name" content="Bahn-Pinneberg.de">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= nb_e($pageUrl) ?>">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= SITE_URL ?>/og/info.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'aktuell') ?>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>Meldungen</h1>
		</div>
	</div>

<?= nb_render_meldungen_tabs($root, 'aktuell') ?>

<?php if ($ruhig): ?>
	<section class="section">
		<ol class="feed card"><li><p class="feed__empty">Gerade ist nichts los: keine Infrastrukturst&ouml;rung, kein Baufahrplan, keine Eilmeldung der letzten vier Stunden<?= $gfAktiv ? ', keine HVV-Meldung' : '' ?>.</p></li></ol>
	</section>
<?php endif; ?>

<?php if ($bau): ?>
	<section class="section">
		<h2 class="section__title">Baufahrplan</h2>
		<div class="card aktuell__bau">
			<p><strong>Nordbahn f&auml;hrt nach Baufahrplan.</strong> <?= nb_e(nb_baufahrplan_text($bau)) ?><?= !empty($bau['link']) ? ' &middot; <a href="' . nb_e($bau['link']) . '" rel="noopener nofollow">Sonderfahrplan bei der Nordbahn &#8599;</a>' : '' ?></p>
		</div>
	</section>
<?php endif; ?>

<?php if ($stoerungen): ?>
	<section class="section">
		<h2 class="section__title">Laufende Infrastrukturst&ouml;rungen</h2>
		<ul class="incidents">
<?php foreach ($stoerungen as $st): ?>
			<?= nb_render_incident($st) ?>

<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<?php if ($hinweise): ?>
	<section class="section">
		<h2 class="section__title">Hinweise ohne sp&uuml;rbare Folgen</h2>
		<ul class="incidents">
<?php foreach ($hinweise as $st): ?>
			<?= nb_render_incident($st) ?>

<?php endforeach; ?>
		</ul>
		<p class="section__note">Laut strecken-info.de noch aktiv, f&uuml;r Pendler laut Meldung ohne Auswirkung. Sie z&auml;hlen nicht als St&ouml;rung, stehen nicht auf der Startseite und l&ouml;sen keine Benachrichtigung aus.</p>
	</section>
<?php endif; ?>

<?php if ($meldungen): ?>
	<section class="section">
		<h2 class="section__title">Aktuelle Meldungen</h2>
		<div class="card meldungen"><?= nb_render_aktuelle_meldungen($meldungen, $now, false) ?></div>
	</section>
<?php endif; ?>

	<p class="section__note"><?= $stand ? 'Stand ' . nb_e(nb_fmt_wann($stand, $now)) . '. ' : '' ?>Hier stehen immer alle Meldungen. Auf der Startseite l&auml;sst sich jede einzelne wegklicken, das gilt nur dort und nur im eigenen Browser.</p>

</main>

<?php $current = 'aktuell'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
