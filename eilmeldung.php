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
// Absoluter Wurzelpfad: die Adresse hat mit Slug drei Ebenen, ohne zwei
$root = rtrim((string) parse_url(nordbahn_site_url(), PHP_URL_PATH), '/') . '/';
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
    $pageUrl = nb_ereignis_url('eilmeldung', $id, $titel);
    nb_slug_pruefen($pageUrl, $titel);
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
			<a class="zurueck" href="<?= $root ?>"><svg class="zurueck__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20v-2Z"/></svg><span class="zurueck__lang">Zur&uuml;ck zur Startseite</span><span class="zurueck__kurz">Startseite</span></a>
		</nav>
	</div>
</header>

<main class="wrap">
<?php if (!$gefunden): ?>
	<article class="artikel">
		<header class="artikel__kopf">
			<p class="artikel__dachzeile"><span class="artikel__rubrik artikel__rubrik--eil">Eilmeldung</span></p>
			<h1 class="artikel__titel">Eilmeldung nicht gefunden</h1>
			<p class="artikel__vorspann">Diese Eilmeldung ist nicht im Archiv. Alle erfassten Eilmeldungen stehen unter <a href="<?= $root ?>eilmeldungen/">Alle Eilmeldungen</a>.</p>
		</header>
	</article>
<?php else: ?>
	<article class="artikel">
		<header class="artikel__kopf">
			<p class="artikel__dachzeile">
				<span class="artikel__rubrik artikel__rubrik--eil">Eilmeldung der Nordbahn</span>
				<span><?= nb_e(nb_fmt_tag_lang($ts, $now)) ?></span>
			</p>
			<h1 class="artikel__titel"><?= nb_e($titel) ?></h1>
<?php if ($em['text'] !== ''): ?>
			<p class="artikel__vorspann"><?= nb_e($em['text']) ?></p>
<?php else: ?>
			<p class="artikel__vorspann artikel__vorspann--leer">Zu dieser Meldung liegt kein Pendler-Text vor. Was die Nordbahn gemeldet hat, steht unten.</p>
<?php endif; ?>
			<p class="artikel__meta">
				<span>Stand <time datetime="<?= date('c', $ts) ?>"><?= nb_e(nb_fmt_tag_lang($ts, $now)) ?>, <?= nb_e(nb_fmt_uhr($ts)) ?></time></span>
<?php if ($em['link']): ?>
				<span>Quelle <a href="<?= nb_e($em['link']) ?>" rel="noopener nofollow">nordbahn.de</a></span>
<?php else: ?>
				<span>Quelle nordbahn.de</span>
<?php endif; ?>
			</p>
		</header>

		<section class="section">
			<h2 class="section__title">Was die Nordbahn gemeldet hat</h2>
			<blockquote class="quelle quelle--eil">
				<p class="quelle__label">Eilmeldung auf nordbahn.de</p>
				<p class="quelle__titel"><?= nb_e($em['original'] !== '' ? $em['original'] : $titel) ?></p>
<?php if ($em['link']): ?>
				<p class="quelle__fuss"><a href="<?= nb_e($em['link']) ?>" rel="noopener nofollow">Originalmeldung lesen &#8599;</a></p>
<?php else: ?>
				<p class="quelle__fuss">Die Nordbahn f&uuml;hrt diese Meldung inzwischen nicht mehr auf ihrer Seite.</p>
<?php endif; ?>
			</blockquote>
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
			<div class="card zuege-wrap"><?= nb_render_zuege_liste($zuege) ?></div>
			<p class="section__note">Beobachtet an den Bahnh&ouml;fen, die in dieser Zeit abgefragt wurden. Ohne Beobachtung fehlt ein Zug hier, auch wenn er betroffen war.</p>
		</section>
<?php endif; ?>

		<footer class="artikel__fuss">
			<?= nb_render_teilen($pageUrl, $titel) ?>
			<?= nb_render_weiter(['Zurück zur Startseite' => $root, 'Alle Eilmeldungen' => $root . 'eilmeldungen/#' . nb_anker($id), 'Störungsstatistik' => $root . 'statistik/']) ?>
			<p class="artikel__hinweis">Titel und Pendler-Text werden automatisch aus der Eilmeldung der Nordbahn formuliert. Ma&szlig;geblich ist die Originalmeldung.</p>
		</footer>
	</article>
<?php endif; ?>
</main>

<?php $current = 'ereignis'; require __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
