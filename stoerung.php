<?php
/**
 * Ereignisseite einer Infrastrukturstoerung (/stoerung/<key>/): Pendler-Text,
 * Originalmeldung, Verlauf, betroffene Zuege, Eilmeldungen und weitere Stoerungen
 * im Zeitraum. Daten aus dem Logbuch und dem Zuglog, kein JavaScript noetig.
 */

require __DIR__ . '/api/store.php';
require __DIR__ . '/api/render.php';
require __DIR__ . '/inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../../';
nb_root($root);
$assetVersion = (int) @filemtime(__DIR__ . '/dashboard.css');

$key = isset($_GET['key']) ? nb_anker((string) $_GET['key']) : '';
$data = nordbahn_dataset();
$st = $key !== '' ? nb_stoerung_finden($data, $key) : null;
$gefunden = $st !== null;
$now = time();

if (!$gefunden) {
    http_response_code(404);
    $title = 'Störung nicht gefunden – Nordbahn Monitor';
    $ogTitle = 'Störung nicht gefunden';
    $description = 'Diese Störung ist nicht im Logbuch des Nordbahn Monitors.';
    $pageUrl = SITE_URL . '/stoerungen/';
    $ogBild = 'info';
    $jsonLd = null;
} else {
    $dauer = nb_dauer($st['ende'] - $st['start']);
    $title = $st['titel'] . ' – ' . date('d.m.Y', $st['start']) . ' – Nordbahn Monitor';
    $ogTitle = $st['titel'];
    $description = ($st['ki_text'] !== '' ? $st['ki_text'] : ($st['text'] !== '' ? $st['text'] : $st['titel_technisch']))
        . ' Störung auf der Nordbahn-Strecke ' . ($st['aktiv'] ? 'seit ' : 'vom ') . nb_fmt_datetime(date('c', $st['start'])) . ($st['aktiv'] ? ', dauert an.' : ', aufgehoben nach ' . $dauer . '.');
    $description = mb_substr($description, 0, 300);
    $pageUrl = nb_ereignis_url('stoerung', $key);
    $ogBild = $st['aktiv'] ? 'alert' : 'ok';
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'NewsArticle',
        'headline' => $st['titel'],
        'description' => $description,
        'datePublished' => date('c', $st['start']),
        'dateModified' => date('c', $st['ende']),
        'inLanguage' => 'de',
        'mainEntityOfPage' => $pageUrl,
        'author' => ['@type' => 'Organization', 'name' => 'Nordbahn Monitor', 'url' => SITE_URL . '/'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Nordbahn Monitor', 'url' => SITE_URL . '/'],
    ];
    $zuege = nb_zuege_im_zeitraum($st['start'], $st['ende']);
    $eil = nb_eilmeldungen_im_zeitraum(nb_items($data), $st['start'], $st['ende']);
    $andere = nb_stoerungen_im_zeitraum($data, $st['start'], $st['ende'], $key);
    $schritte = nb_verlauf_schritte($st['verlauf']);
    $abschnitte = [];
    foreach ($st['abschnitte'] as $a) {
        $teil = implode(' – ', array_filter([$a['von'] ?? '', $a['bis'] ?? '']));
        if ($teil !== '') {
            $abschnitte[] = $teil;
        }
    }
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
	<div class="page-head"><div><h1>St&ouml;rung nicht gefunden</h1><p class="page-head__lead">Diese St&ouml;rung ist nicht im Logbuch. Alle erfassten St&ouml;rungen stehen im <a href="<?= $root ?>stoerungen/">Archiv</a>.</p></div></div>
<?php else: ?>
	<div class="page-head">
		<div>
			<p class="section__note">Infrastrukturst&ouml;rung · <?= nb_e(nb_fmt_wann($st['start'], $now)) ?></p>
			<h1><?= nb_e($st['titel']) ?> <span class="ereignis__status <?= $st['aktiv'] ? 'ereignis__status--aktiv' : 'ereignis__status--aufgehoben' ?>"><?= $st['aktiv'] ? 'aktiv' : 'aufgehoben' ?></span></h1>
			<p class="page-head__lead"><?= $st['aktiv'] ? 'Seit ' . nb_e(nb_fmt_wann($st['start'], $now, true)) . ', bisher ' . nb_e($dauer) : nb_e(nb_fmt_wann($st['start'], $now)) . ' ' . nb_e(nb_fmt_bis($st['start'], $st['ende'], $now)) . ', ' . nb_e($dauer) ?><?php if ($st['aktiv'] && $st['ende_gemeldet'] !== ''): ?>, <?= $st['abgelaufen'] ? 'gemeldetes Ende ' . nb_e(nb_fmt_datetime($st['ende_gemeldet'])) . ' &uuml;berschritten' : 'voraussichtlich bis ' . nb_e(nb_fmt_datetime($st['ende_gemeldet'])) ?><?php endif; ?>.</p>
		</div>
	</div>

	<section class="section">
		<div class="card prose">
<?php if ($st['ki_text'] !== ''): ?>
			<p class="ereignis__text"><?= nb_e($st['ki_text']) ?></p>
<?php endif; ?>
			<h2>Meldung von strecken-info.de</h2>
			<p class="ereignis__original"><strong><?= nb_e($st['titel_technisch']) ?></strong><?= $st['text'] !== '' ? "\n" . nb_e($st['text']) : '' ?></p>
<?php if ($abschnitte || $st['betriebsstellen'] || $st['wirkungen'] || $st['einschraenkung'] !== ''): ?>
			<dl class="incident__meta">
<?php if ($abschnitte): ?><div><dt>Abschnitt</dt><dd><?= nb_e(implode(', ', $abschnitte)) ?></dd></div><?php elseif ($st['betriebsstellen']): ?><div><dt>Betriebsstellen</dt><dd><?= nb_e(implode(', ', $st['betriebsstellen'])) ?></dd></div><?php endif; ?>
<?php if ($st['wirkungen']): ?><div><dt>Auswirkung</dt><dd><?= nb_e(implode(', ', $st['wirkungen'])) ?></dd></div><?php endif; ?>
<?php if ($st['einschraenkung'] !== ''): ?><div><dt>Einschr&auml;nkung</dt><dd><?= nb_e(mb_strtoupper(mb_substr($st['einschraenkung'], 0, 1)) . mb_substr($st['einschraenkung'], 1)) ?></dd></div><?php endif; ?>
			</dl>
<?php endif; ?>
		</div>
	</section>

<?php if ($schritte): ?>
	<section class="section">
		<h2 class="section__title">Verlauf</h2>
		<div class="card"><?= nb_render_verlauf_schritte($schritte, $now) ?></div>
	</section>
<?php endif; ?>

	<section class="section">
		<h2 class="section__title">Betroffene Z&uuml;ge</h2>
<?php if ($zuege['gesamt']): ?>
		<p class="section__note"><?= (int) $zuege['gesamt'] ?> auff&auml;llige Abfahrten im Zeitraum: <?= (int) $zuege['ausfaelle'] ?> ausgefallen, <?= (int) $zuege['teil'] ?> nur teilweise gefahren, <?= (int) $zuege['spaet'] ?> mit mindestens f&uuml;nf Minuten Versp&auml;tung<?= $zuege['gesamt'] > count($zuege['zuege']) ? ', die ersten ' . count($zuege['zuege']) . ' hier' : '' ?>.</p>
<?php endif; ?>
		<div class="card"><?= nb_render_zuege_liste($zuege) ?></div>
		<p class="section__note">Beobachtet an den Bahnh&ouml;fen, die in dieser Zeit abgefragt wurden; ohne Beobachtung fehlt ein Zug hier, auch wenn er betroffen war.</p>
	</section>

<?php if ($eil): ?>
	<section class="section">
		<h2 class="section__title">Eilmeldungen der Nordbahn im Zeitraum</h2>
		<ol class="feed card"><?= nb_render_feed($eil, 10, 0, $now) ?></ol>
	</section>
<?php endif; ?>

<?php if ($andere): ?>
	<section class="section">
		<h2 class="section__title">Weitere St&ouml;rungen im Zeitraum</h2>
		<ol class="feed card"><?= nb_render_verlauf($andere, 10, 0, $now) ?></ol>
	</section>
<?php endif; ?>

	<section class="section">
		<ul class="ereignis__links">
			<li><a href="<?= $root ?>">Aktuelle Lage</a></li>
			<li><a href="<?= $root ?>stoerungen/#<?= nb_e(nb_anker($key)) ?>">Im Archiv</a></li>
			<li><a href="<?= $root ?>statistik/">St&ouml;rungsstatistik</a></li>
			<li><a href="https://t.me/prisdorfpendler" rel="noopener">Diskutiert mit auf Telegram</a></li>
			<li><a href="https://strecken-info.de/#/suche/<?= nb_e(rawurlencode($key)) ?>" rel="noopener nofollow">Bei strecken-info.de</a></li>
		</ul>
	</section>
<?php endif; ?>
</main>

<?php $current = 'ereignis'; require __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
