<?php
/**
 * Wichtige Meldungen (/news/): Stoerungen und Eilmeldungen, die viele Pendler
 * betreffen (Regeln in nb_wichtig_stoerung/nb_wichtig_eilmeldung), juengste
 * zuerst, ein Abschnitt je Tag. Rein server-seitig.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
nb_root($root);
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$data = nordbahn_dataset();
$alle = nb_wichtig_items($data);
$anzahl = count($alle);
$seit = $alle ? $alle[$anzahl - 1]['ts'] : null;
$blatt = nb_seite($alle, 30, $_GET['seite'] ?? 1);
$items = $blatt['items'];
$basis = SITE_URL . '/news/';
$pageUrl = nb_seite_url($basis, $blatt['seite']);

$title = 'Wichtige Meldungen zur Nordbahn RB61/RB71 – Störungen, Ersatzverkehr, Sperrungen';
$description = 'Die ' . $anzahl . ' wichtigen Meldungen zur Nordbahn zwischen Hamburg, Pinneberg, Elmshorn, Itzehoe und Wrist'
    . ($seit ? ' seit dem ' . date('d.m.Y', $seit) : '') . ': größere Störungen, Ersatzverkehr, Sperrungen und Bauarbeiten, ohne einzelne kurzfristige Zugausfälle.';
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
<meta property="og:site_name" content="Bahn-Pinneberg.de">
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
		<a class="site-head__brand" href="<?= $root ?>">Bahn-Pinneberg.de</a>
		<nav class="site-head__tools" aria-label="Zur&uuml;ck">
			<a class="tool" href="<?= $root ?>">Zur Lage</a>
		</nav>
	</div>
</header>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>Wichtige Meldungen</h1>
			<p class="page-head__lead">Was viele Pendler betrifft: St&ouml;rungen mit schwerer Einschr&auml;nkung, Ausf&auml;llen, Umleitungen oder Ersatzverkehr sowie Eilmeldungen zu Ersatzverkehr, Sperrungen, Bauarbeiten oder mehreren Z&uuml;gen. Einzelne kurzfristige Zugausf&auml;lle stehen bei <a href="<?= $root ?>eilmeldungen/">allen Eilmeldungen</a>.</p>
			<ul class="weiter">
				<li><a href="<?= $root ?>eilmeldungen/">Alle Eilmeldungen</a></li>
				<li><a href="<?= $root ?>stoerungen/">Alle St&ouml;rungen</a></li>
			</ul>
		</div>
	</div>

<?= nb_render_seitennav($blatt, './') ?>
<?= nb_render_news($items) ?>
<?= nb_render_seitennav($blatt, './') ?>

	<section class="section">
		<p class="section__note">Die Auswahl trifft die Seite automatisch nach festen Regeln, siehe <a href="<?= $root ?>faq/">H&auml;ufige Fragen</a>.</p>
	</section>
</main>

<?php $current = 'news'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
