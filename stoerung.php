<?php
/**
 * Ereignisseite einer Infrastrukturstoerung (/stoerung/<key>/): Pendler-Text,
 * Originalmeldung, Verlauf, betroffene Zuege, Eilmeldungen und weitere Stoerungen
 * im Zeitraum. Daten aus dem Logbuch und dem Zuglog, kein JavaScript noetig.
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

$key = isset($_GET['key']) ? nb_anker((string) $_GET['key']) : '';
$data = nordbahn_dataset();
$st = $key !== '' ? nb_stoerung_finden($data, $key) : null;
$gefunden = $st !== null;
$now = time();

if (!$gefunden) {
    http_response_code(404);
    $title = 'Störung nicht gefunden – Bahn-Pinneberg.de';
    $ogTitle = 'Störung nicht gefunden';
    $description = 'Diese Störung ist nicht im Logbuch von Bahn-Pinneberg.de.';
    $pageUrl = SITE_URL . '/stoerungen/';
    $ogBild = 'info';
    $jsonLd = null;
} else {
    $dauer = nb_dauer($st['ende'] - $st['start']);
    $title = $st['titel'] . ' – ' . date('d.m.Y', $st['start']) . ' – Bahn-Pinneberg.de';
    $ogTitle = $st['titel'];
    $description = ($st['ki_text'] !== '' ? $st['ki_text'] : ($st['text'] !== '' ? $st['text'] : $st['titel_technisch']))
        . ' Störung auf der Nordbahn-Strecke ' . ($st['aktiv'] ? 'seit ' : 'vom ') . nb_fmt_datetime(date('c', $st['start'])) . ($st['aktiv'] ? ', dauert an.' : ', aufgehoben nach ' . $dauer . '.');
    $description = mb_substr($description, 0, 300);
    $pageUrl = nb_ereignis_url('stoerung', $key, $st['titel']);
    nb_slug_pruefen($pageUrl, $st['titel']);
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
        'author' => ['@type' => 'Organization', 'name' => 'Bahn-Pinneberg.de', 'url' => SITE_URL . '/'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Bahn-Pinneberg.de', 'url' => SITE_URL . '/'],
    ];
    $zuege = nb_zuege_im_zeitraum($st['start'], $st['ende']);
    $eil = nb_eilmeldungen_im_zeitraum(nb_items($data), $st['start'], $st['ende']);
    $andere = nb_stoerungen_im_zeitraum($data, $st['start'], $st['ende'], $key);
    $schritte = nb_verlauf_schritte($st['verlauf'], $st['aktiv'] ? null : $st['ende']);
    $endeGemeldet = nb_ts($st['ende_gemeldet']);
    // Jede Angabe nur einmal: Vorspann oben, der Verlauf ohne den Text, der schon oben
    // steht; Technisches aus der Originalmeldung eingeklappt.
    $vorspann = $st['ki_text'] !== '' ? $st['ki_text'] : $st['text'];
    $wirkungen = array_values(array_unique(array_filter(array_map('nb_wirkung_lesbar', $st['wirkungen']))));
    // Mit Pendler-Text sagt der Vorspann schon, was passiert; die Wirkung gehoert dann zur Quelle
    $wirkungKachel = $st['ki_text'] === '' ? $wirkungen : [];
    $wirkungQuelle = $st['ki_text'] !== '' ? $wirkungen : [];
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
			<p class="artikel__dachzeile"><span class="artikel__rubrik">Infrastrukturst&ouml;rung</span></p>
			<h1 class="artikel__titel">St&ouml;rung nicht gefunden</h1>
			<p class="artikel__vorspann">Diese St&ouml;rung ist nicht im Logbuch. Alle erfassten St&ouml;rungen stehen im <a href="<?= $root ?>stoerungen/">Archiv</a>.</p>
		</header>
	</article>
<?php else: ?>
	<article class="artikel">
		<header class="artikel__kopf">
			<p class="artikel__dachzeile">
				<span class="artikel__rubrik">Infrastrukturst&ouml;rung</span>
			</p>
			<h1 class="artikel__titel"><?= nb_e($st['titel']) ?></h1>
<?php if ($vorspann !== ''): ?>
			<p class="artikel__vorspann"><?= nb_e($vorspann) ?></p>
<?php endif; ?>
			<div class="artikel__status">
				<span class="pill <?= $st['aktiv'] ? 'pill--aktiv pill--live' : 'pill--aufgehoben' ?>"><?= $st['aktiv'] ? 'Aktiv' : 'Aufgehoben' ?></span>
<?php if (nb_stoerung_hinweis($st)): ?>
				<span class="pill pill--teil" title="F&uuml;r Pendler laut Meldung ohne Auswirkung; z&auml;hlt nicht als St&ouml;rung">Ohne sp&uuml;rbare Folgen</span>
<?php endif; ?>
				<p class="zeitraum"><time datetime="<?= date('c', $st['start']) ?>"><?= nb_e(nb_zeitraum_satz($st['start'], $st['aktiv'] ? $endeGemeldet : null, $now, $st['aktiv'] ? null : $st['ende'], $st['abgelaufen'])) ?></time></p>
<?php if ($st['aktiv']): ?>
				<?= nb_render_zeitbalken($st['start'], $endeGemeldet, $now, $st['abgelaufen']) ?>

<?php endif; ?>
			</div>
		</header>

<?php if ($abschnitte || $st['betriebsstellen'] || $wirkungKachel || $st['einschraenkung'] !== ''): ?>
		<aside class="card fakten" aria-label="Fakten zur St&ouml;rung">
			<dl>
<?php if ($abschnitte): ?>
				<div><dt>Abschnitt</dt><dd><?= nb_e(implode(', ', $abschnitte)) ?></dd></div>
<?php elseif ($st['betriebsstellen']): ?>
				<div><dt>Betriebsstellen</dt><dd><?= nb_e(implode(', ', $st['betriebsstellen'])) ?></dd></div>
<?php endif; ?>
<?php if ($wirkungKachel): ?>
				<div><dt>Auswirkung</dt><dd><?= nb_e(implode(', ', $wirkungKachel)) ?></dd></div>
<?php endif; ?>
<?php if ($st['einschraenkung'] !== ''): ?>
				<div><dt>Einschr&auml;nkung</dt><dd><?= nb_e(mb_strtoupper(mb_substr($st['einschraenkung'], 0, 1)) . mb_substr($st['einschraenkung'], 1)) ?></dd></div>
<?php endif; ?>
			</dl>
		</aside>
<?php endif; ?>

<?php if ($schritte): ?>
		<section class="section">
			<h2 class="section__title">Verlauf</h2>
			<?= nb_render_verlauf_schritte($schritte, $now, ['kopf_text' => $vorspann, 'ohne_dauer' => true]) ?>
		</section>
<?php endif; ?>

		<section class="section">
			<h2 class="section__title">Betroffene Z&uuml;ge</h2>
<?php if ($zuege['gesamt']): ?>
			<p class="artikel__vorspann" style="font-size: 15px; margin-bottom: 12px;"><?= (int) $zuege['gesamt'] ?> auff&auml;llige Abfahrten im Zeitraum: <?= (int) $zuege['ausfaelle'] ?> ausgefallen, <?= (int) $zuege['teil'] ?> nur teilweise gefahren, <?= (int) $zuege['spaet'] ?> mit mindestens f&uuml;nf Minuten Versp&auml;tung<?= $zuege['gesamt'] > count($zuege['zuege']) ? ', die ersten ' . count($zuege['zuege']) . ' hier' : '' ?>.</p>
<?php endif; ?>
			<div class="card zuege-wrap"><?= nb_render_zuege_liste($zuege) ?></div>
			<p class="section__note">Beobachtet an den Bahnh&ouml;fen, die in dieser Zeit abgefragt wurden. Ohne Beobachtung fehlt ein Zug hier, auch wenn er betroffen war.</p>
		</section>

<?php if ($eil): ?>
		<section class="section">
			<h2 class="section__title">Eilmeldungen der Nordbahn dazu</h2>
			<ol class="feed card"><?= nb_render_feed($eil, 10, 0, $now) ?></ol>
		</section>
<?php endif; ?>

<?php if ($andere): ?>
		<section class="section">
			<h2 class="section__title">Weitere St&ouml;rungen zur selben Zeit</h2>
			<ol class="feed card"><?= nb_render_verlauf($andere, 10, 0, $now) ?></ol>
		</section>
<?php endif; ?>

		<section class="section">
		<details class="quelle quelle--klapp">
			<summary class="quelle__label">Originalmeldung der DB InfraGO</summary>
			<p class="quelle__titel"><?= nb_e($st['titel_technisch']) ?></p>
<?php if ($st['text'] !== '' && $st['text'] !== $vorspann): ?>
			<p class="quelle__text"><?= nb_e($st['text']) ?></p>
<?php endif; ?>
<?php if ($wirkungQuelle): ?>
			<p class="quelle__text">Auswirkung: <?= nb_e(implode(', ', $wirkungQuelle)) ?></p>
<?php endif; ?>
			<p class="quelle__fuss"><a href="https://strecken-info.de/#/suche/<?= nb_e(rawurlencode($key)) ?>" rel="noopener nofollow">Meldung bei strecken-info.de &#8599;</a></p>
		</details>
		</section>

	</article>
<?php endif; ?>
</main>

<?php $current = 'ereignis'; require __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
