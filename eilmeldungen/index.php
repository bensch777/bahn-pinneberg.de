<?php
/**
 * Archiv aller Eilmeldungen der Nordbahn (Historie aus cache/data.json plus
 * Live-Liste), juengste zuerst, ein Abschnitt je Tag. Rein server-seitig.
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
$alle = nb_archiv_eil($data);
$anzahl = count($alle);
$seit = $alle ? $alle[$anzahl - 1]['ts'] : null;
$blatt = nb_seite($alle, 50, $_GET['seite'] ?? 1);
$items = $blatt['items'];
$basis = SITE_URL . '/eilmeldungen/';
$pageUrl = nb_seite_url($basis, $blatt['seite']);

$title = 'Eilmeldungen der Nordbahn – Archiv RB61/RB71 Hamburg, Pinneberg, Elmshorn, Itzehoe, Wrist';
$description = 'Alle ' . $anzahl . ' Eilmeldungen der Nordbahn zu Zugausfällen und Verspätungen auf RB61 und RB71'
    . ($seit ? ' seit dem ' . date('d.m.Y', $seit) : '') . ', nach Tagen sortiert, mit Pendler-Titel und Link zur Originalmeldung.';
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
			<h1>Eilmeldungen</h1>
			<p class="page-head__lead">Alle Eilmeldungen der Nordbahn zu Zugausf&auml;llen und Verspätungen auf RB61 und RB71<?php if ($seit): ?>, erfasst seit dem <?= nb_e(date('d.m.Y', $seit)) ?><?php endif; ?>. Verlinkt ist, was noch auf nordbahn.de steht.</p>
		</div>
	</div>

<?= nb_render_seitennav($blatt, './') ?>
<?= nb_render_archiv_eil($items) ?>
<?= nb_render_seitennav($blatt, './') ?>

	<section class="section">
		<p class="section__note">Quelle: Eilmeldungen auf nordbahn.de, alle zwei Minuten abgeholt. Steht ein Pendler-Titel aus dem Alert-Workflow bereit, zeigt die Liste ihn; der Originaltitel der Nordbahn steht im Tooltip. Die Uhrzeit ist der Stand der Nordbahn, sonst der Zeitpunkt der Erfassung.</p>
	</section>
</main>

<?php $current = 'eilmeldungen'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
