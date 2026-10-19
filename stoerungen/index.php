<?php
/**
 * Archiv aller Infrastrukturstoerungen aus dem Logbuch (cache/stoerungen-log.json),
 * aktive eingeschlossen, nach Beginn, ein Abschnitt je Tag. Rein server-seitig.
 */

require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
nb_root($root);
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$data = nordbahn_dataset();
$alle = nb_archiv_stoerungen($data);
$anzahl = count($alle);
$seit = $alle ? min(array_column($alle, 'start')) : null;
$aktiv = count(array_filter($alle, function ($it) { return $it['aktiv']; }));
$blatt = nb_seite($alle, 50, $_GET['seite'] ?? 1);
$items = $blatt['items'];
$basis = SITE_URL . '/stoerungen/';
$pageUrl = nb_seite_url($basis, $blatt['seite']);

$title = 'Infrastrukturstörungen der Nordbahn – Archiv RB61/RB71 Hamburg, Pinneberg, Elmshorn, Itzehoe, Wrist';
$description = 'Alle ' . $anzahl . ' Infrastrukturstörungen auf der Nordbahn-Strecke Hamburg – Elmshorn – Itzehoe / Wrist'
    . ($seit ? ' seit dem ' . date('d.m.Y', $seit) : '') . ', nach Tagen sortiert, mit Beginn, Ende, Dauer und Pendler-Text aus den Meldungen von strecken-info.de.';
if ($blatt['seite'] > 1) {
    $title .= ' – Seite ' . $blatt['seite'];
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $title,
    'description' => $description,
    'url' => $pageUrl,
    'inLanguage' => 'de',
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Nordbahn Monitor', 'url' => SITE_URL . '/'],
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
<?php if ($blatt['seite'] > 1): ?>
<link rel="prev" href="<?= nb_e(nb_seite_url($basis, $blatt['seite'] - 1)) ?>">
<?php endif; ?>
<?php if ($blatt['seite'] < $blatt['seiten']): ?>
<link rel="next" href="<?= nb_e(nb_seite_url($basis, $blatt['seite'] + 1)) ?>">
<?php endif; ?>
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
			<h1>Infrastrukturst&ouml;rungen</h1>
			<p class="page-head__lead">Alle St&ouml;rungen auf der Strecke Hamburg &ndash; Elmshorn &ndash; Itzehoe / Wrist laut strecken-info.de<?php if ($seit): ?>, erfasst seit dem <?= nb_e(date('d.m.Y', $seit)) ?><?php endif; ?><?php if ($aktiv): ?>, davon <?= (int) $aktiv ?> aktuell aktiv<?php endif; ?>. Kennzahlen dazu in der <a href="<?= $root ?>statistik/">St&ouml;rungsstatistik</a>.</p>
		</div>
	</div>

<?= nb_render_seitennav($blatt, './') ?>
<?= nb_render_archiv_stoerungen($items) ?>
<?= nb_render_seitennav($blatt, './') ?>

	<section class="section">
		<p class="section__note">Quelle: St&ouml;rungsmeldungen der DB InfraGO auf strecken-info.de f&uuml;r den Abschnitt Hamburg &ndash; Itzehoe / Wrist. Beginn ist das erste Auftreten der Meldung, Ende ihre Aufhebung. Eilmeldungen der Nordbahn stehen im <a href="<?= $root ?>eilmeldungen/">Eilmeldungs-Archiv</a>.</p>
	</section>
</main>

<?php $current = 'stoerungen'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
