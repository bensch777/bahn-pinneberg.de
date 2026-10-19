<?php
/**
 * Ereignisseite einer Eilmeldung der Nordbahn (/eilmeldung/<id>/): Pendler-Titel
 * und -Text, Nordbahn-Titel, Stand, Link zur Nordbahn (solange sie dort steht),
 * Stoerungen und auffaellige Zuege zur selben Zeit.
 */

require __DIR__ . '/api/store.php';
require __DIR__ . '/api/render.php';
require __DIR__ . '/inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../../';
nb_root($root);
$assetVersion = (int) @filemtime(__DIR__ . '/dashboard.css');

$id = isset($_GET['id']) ? nb_anker((string) $_GET['id']) : '';
$data = nordbahn_dataset();
$em = $id !== '' ? nb_eilmeldung_finden($data, $id) : null;
$gefunden = $em !== null;
$now = time();

if (!$gefunden) {
    http_response_code(404);
    $title = 'Eilmeldung nicht gefunden – Nordbahn Monitor';
    $ogTitle = 'Eilmeldung nicht gefunden';
    $description = 'Diese Eilmeldung ist nicht im Archiv des Nordbahn Monitors.';
    $pageUrl = SITE_URL . '/eilmeldungen/';
    $ogBild = 'info';
    $jsonLd = null;
} else {
    $ts = $em['stand'] ?? $em['ts'];
    $titel = $em['title'] !== '' ? $em['title'] : $em['id'];
    $title = $titel . ' – Eilmeldung der Nordbahn vom ' . date('d.m.Y', $ts) . ' – Nordbahn Monitor';
    $ogTitle = $titel;
    $description = mb_substr(($em['text'] !== '' ? $em['text'] . ' ' : '') . 'Eilmeldung der Nordbahn vom ' . nb_fmt_datetime(date('c', $ts)) . ($em['original'] !== '' && $em['original'] !== $titel ? ' („' . $em['original'] . '“).' : '.'), 0, 300);
    $pageUrl = nb_ereignis_url('eilmeldung', $id);
    $ogBild = 'warn';
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $titel,
        'description' => $description,
        'datePublished' => date('c', $ts),
        'inLanguage' => 'de',
        'mainEntityOfPage' => $pageUrl,
        'author' => ['@type' => 'Organization', 'name' => 'Nordbahn Monitor', 'url' => SITE_URL . '/'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Nordbahn Monitor', 'url' => SITE_URL . '/'],
    ];
    $stoerungen = nb_stoerungen_im_zeitraum($data, $ts - 3600, $ts + 3 * 3600);
    $zuege = nb_zuege_im_zeitraum($ts - 1800, $ts + 4 * 3600, 0, 20);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= nb_e($pageUrl) ?>">
<meta name="robots" content="<?= $gefunden ? 'index, follow' : 'noindex' ?>">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Nordbahn Monitor">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= nb_e($pageUrl) ?>">
<meta property="og:title" content="<?= nb_e($ogTitle) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= SITE_URL ?>/og/<?= $ogBild ?>.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<link rel="icon" type="image/svg+xml" href="<?= $root ?>favicon.svg">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<?php if ($jsonLd): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php require __DIR__ . '/inc/analytics.php'; ?>
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
<?php if (!$gefunden): ?>
	<div class="page-head"><div><h1>Eilmeldung nicht gefunden</h1><p class="page-head__lead">Diese Eilmeldung ist nicht im Archiv. Alle erfassten Eilmeldungen stehen unter <a href="<?= $root ?>eilmeldungen/">Alle Eilmeldungen</a>.</p></div></div>
<?php else: ?>
	<div class="page-head">
		<div>
			<p class="section__note">Eilmeldung der Nordbahn · Stand <?= nb_e(nb_fmt_wann($ts, $now)) ?></p>
			<h1><?= nb_e($titel) ?></h1>
<?php if ($em['original'] !== '' && $em['original'] !== $titel): ?>
			<p class="page-head__lead">Nordbahn-Titel: <?= nb_e($em['original']) ?></p>
<?php endif; ?>
		</div>
	</div>

	<section class="section">
		<div class="card prose">
<?php if ($em['text'] !== ''): ?>
			<p class="ereignis__text"><?= nb_e($em['text']) ?></p>
<?php else: ?>
			<p class="ereignis__text">Zu dieser Meldung liegt kein Pendler-Text vor.</p>
<?php endif; ?>
<?php if ($em['link']): ?>
			<p><a href="<?= nb_e($em['link']) ?>" rel="noopener nofollow">Originalmeldung auf nordbahn.de &#8599;</a></p>
<?php else: ?>
			<p class="section__note">Die Nordbahn hat die Meldung inzwischen von ihrer Seite genommen.</p>
<?php endif; ?>
		</div>
	</section>

<?php if ($stoerungen): ?>
	<section class="section">
		<h2 class="section__title">Infrastrukturst&ouml;rungen zu dieser Zeit</h2>
		<ol class="feed card"><?= nb_render_verlauf($stoerungen, 10, 0, $now) ?></ol>
	</section>
<?php endif; ?>

<?php if ($zuege['gesamt']): ?>
	<section class="section">
		<h2 class="section__title">Auff&auml;llige Z&uuml;ge rund um die Meldung</h2>
		<div class="card"><?= nb_render_zuege_liste($zuege) ?></div>
	</section>
<?php endif; ?>

	<section class="section">
		<ul class="ereignis__links">
			<li><a href="<?= $root ?>">Aktuelle Lage</a></li>
			<li><a href="<?= $root ?>eilmeldungen/#<?= nb_e(nb_anker($id)) ?>">Im Archiv</a></li>
			<li><a href="https://t.me/prisdorfpendler" rel="noopener">Diskutiert mit auf Telegram</a></li>
		</ul>
	</section>
<?php endif; ?>
</main>

<?php $current = 'ereignis'; require __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
