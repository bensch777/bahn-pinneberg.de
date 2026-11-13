<?php
/**
 * Ereignisseite einer Eilmeldung der Nordbahn (/eilmeldung/<id>/): Pendler-Titel
 * und -Text, Status, Link zur Nordbahn (solange sie dort steht), Verlauf und was
 * zur selben Zeit sonst gemeldet war. Gleicher Aufbau wie stoerung.php.
 */

require __DIR__ . '/api/store.php';
require_once __DIR__ . '/api/render.php';
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
    $title = 'Eilmeldung nicht gefunden – Bahn-Pinneberg.de';
    $ogTitle = 'Eilmeldung nicht gefunden';
    $description = 'Diese Eilmeldung ist nicht im Archiv von Bahn-Pinneberg.de.';
    $pageUrl = SITE_URL . '/eilmeldungen/';
    $ogBild = 'info';
    $jsonLd = null;
} else {
    $v = nb_eilmeldung_verlauf($data, $em, $now);
    $ts = $v['start'];
    $titel = $em['title'] !== '' ? $em['title'] : $em['id'];
    $title = $titel . ' – Eilmeldung der Nordbahn vom ' . date('d.m.Y', $ts) . ' – Bahn-Pinneberg.de';
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
        'dateModified' => date('c', $v['schritte'][count($v['schritte']) - 1]['ts']),
        'inLanguage' => 'de',
        'mainEntityOfPage' => $pageUrl,
        'author' => ['@type' => 'Organization', 'name' => 'Bahn-Pinneberg.de', 'url' => SITE_URL . '/'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Bahn-Pinneberg.de', 'url' => SITE_URL . '/'],
    ];
    $bis = $v['ende'] ?? $ts + 3 * 3600;
    $gleichzeitig = nb_gleichzeitig(
        nb_stoerungen_im_zeitraum($data, $ts - 3600, $bis),
        array_values(array_filter(nb_eilmeldungen_im_zeitraum(nb_items($data), $ts, $bis), function ($it) use ($id) {
            return $it['id'] !== $id;
        }))
    );
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
<?= nb_theme_head() ?>
<meta property="og:type" content="article">
<meta property="og:site_name" content="Bahn-Pinneberg.de">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= nb_e($pageUrl) ?>">
<meta property="og:title" content="<?= nb_e($ogTitle) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= SITE_URL ?>/og/<?= $ogBild ?>.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<?php if ($jsonLd): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php require __DIR__ . '/inc/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'ereignis') ?>

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
<?php endif; ?>
			<div class="artikel__status">
<?php if ($v['status'] === 'aktiv'): ?>
				<span class="pill pill--aktiv pill--live">Aktiv</span>
<?php elseif ($v['status'] === 'aufgehoben'): ?>
				<span class="pill pill--aufgehoben">Aufgehoben</span>
<?php else: ?>
				<span class="pill pill--alt">Nicht mehr akut</span>
<?php endif; ?>
				<p class="zeitraum"><time datetime="<?= date('c', $ts) ?>"><?= nb_e($v['status'] === 'alt' ? 'Gemeldet ' . nb_fmt_zeit_rel($ts, $now) : nb_zeitraum_satz($ts, null, $now, $v['ende'])) ?></time></p>
			</div>
<?php if ($em['link']): ?>
			<p class="artikel__quelle">Ganze Meldung mit allen Z&uuml;gen auf <a href="<?= nb_e($em['link']) ?>" rel="noopener nofollow">nordbahn.de &#8599;</a></p>
<?php endif; ?>
		</header>

		<section class="section">
			<h2 class="section__title">Verlauf</h2>
			<?= nb_render_verlauf_schritte($v['schritte'], $now, ['kopf_text' => $em['text'], 'ohne_dauer' => true]) ?>
		</section>

<?php if ($gleichzeitig): ?>
		<section class="section">
			<h2 class="section__title">Zur selben Zeit</h2>
			<ol class="feed card"><?php foreach ($gleichzeitig as $g) { echo nb_render_wichtig_eintrag($g, $now); } ?></ol>
		</section>
<?php endif; ?>


	</article>
<?php endif; ?>
</main>

<?php $current = 'ereignis'; require __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
