<?php
/**
 * Stoerungsstatistik aus dem Logbuch (cache/stoerungen-log.json): letzte 30
 * Tage, acht Kalenderwochen, haeufigste Ursachen. Rein server-seitig, eine
 * Farbe fuer die Balken, Zahlen als Text, Tabelle statt Grafik.
 */

require __DIR__ . '/../api/store.php';
require __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$data = nordbahn_dataset();
$stat = nb_statistik($data['stoerungen_verlauf'] ?? []);
$t30 = $stat['tage30'];
$maxWoche = max(1, max(array_column($stat['wochen'], 'n') ?: [0]));
$maxUrsache = max(1, max(array_column($stat['ursachen'], 'n') ?: [0]));

$title = 'Störungsstatistik Nordbahn RB61/RB71 – Hamburg, Pinneberg, Elmshorn, Itzehoe, Wrist';
$description = $t30['n'] . ' Infrastruktur-Störung' . ($t30['n'] === 1 ? '' : 'en') . ' in den letzten 30 Tagen auf der Nordbahn-Strecke'
    . ($t30['n'] ? ', zusammen ' . nb_dauer($t30['dauer']) : '') . '. Verlauf nach Kalenderwochen und häufigste Ursachen, aus den Meldungen von strecken-info.de.';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Dataset',
    'name' => 'Störungen auf der Nordbahn-Strecke Hamburg – Elmshorn – Itzehoe/Wrist',
    'description' => $description,
    'url' => SITE_URL . '/statistik/',
    'inLanguage' => 'de',
    'isAccessibleForFree' => true,
    'creator' => ['@type' => 'Person', 'name' => 'Benjamin Neske', 'url' => 'https://bensch777.de/'],
    'temporalCoverage' => $stat['seit'] ? date('Y-m-d', $stat['seit']) . '/..' : '',
    'spatialCoverage' => 'Hamburg – Pinneberg – Elmshorn – Itzehoe / Wrist',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/statistik/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Nordbahn Monitor">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/statistik/">
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
			<h1>St&ouml;rungsstatistik</h1>
			<p class="page-head__lead">Infrastruktur-St&ouml;rungen auf der Strecke Hamburg &ndash; Elmshorn &ndash; Itzehoe / Wrist laut strecken-info.de<?php if ($stat['seit']): ?>, erfasst seit dem <?= nb_e(date('d.m.Y', $stat['seit'])) ?><?php endif; ?>. Gez&auml;hlt wird jede Meldung, vom ersten Auftreten bis zur Aufhebung.</p>
		</div>
	</div>

	<section class="section">
		<h2 class="section__title">Letzte 30 Tage</h2>
		<div class="stats">
			<div class="card stat"><p class="stat__label">St&ouml;rungen</p><p class="stat__value"><?= (int) $t30['n'] ?></p></div>
			<div class="card stat"><p class="stat__label">Gesamtdauer</p><p class="stat__value"><?= $t30['n'] ? nb_e(nb_dauer($t30['dauer'])) : '–' ?></p></div>
			<div class="card stat"><p class="stat__label">Dauer im Schnitt</p><p class="stat__value"><?= $t30['n'] ? nb_e(nb_dauer($t30['schnitt'])) : '–' ?></p></div>
			<div class="card stat"><p class="stat__label">L&auml;ngste St&ouml;rung</p><p class="stat__value"><?= $t30['laengste'] ? nb_e(nb_dauer($t30['laengste']['dauer'])) : '–' ?></p><?php if ($t30['laengste']): ?><p class="stat__note"><?= nb_e($t30['laengste']['titel']) ?>, <?= nb_e(date('d.m.', $t30['laengste']['start'])) ?></p><?php endif; ?></div>
		</div>
	</section>

	<section class="section">
		<h2 class="section__title">Nach Kalenderwoche</h2>
		<div class="card">
			<table class="bars">
				<tr><th scope="col">Woche</th><th scope="col">Zeitraum</th><th scope="col" class="num">St&ouml;rungen</th><th scope="col" class="bar"><span class="visually-hidden">Balken</span></th><th scope="col" class="num">Dauer</th></tr>
<?php foreach ($stat['wochen'] as $w): ?>
				<tr>
					<th scope="row">KW <?= (int) $w['kw'] ?></th>
					<td><?= nb_e(date('d.m.', $w['von'])) ?> &ndash; <?= nb_e(date('d.m.', $w['bis'])) ?></td>
					<td class="num"><?= (int) $w['n'] ?></td>
					<td class="bar"><div class="bar__track" aria-hidden="true"><div class="bar__fill<?= $w['n'] ? '' : ' bar__fill--leer' ?>" data-w="<?= nb_bar_stufe($w['n'], $maxWoche) ?>"></div></div></td>
					<td class="num"><?= $w['n'] ? nb_e(nb_dauer($w['dauer'])) : '–' ?></td>
				</tr>
<?php endforeach; ?>
			</table>
		</div>
	</section>

	<section class="section">
		<h2 class="section__title">H&auml;ufigste Ursachen, letzte 90 Tage</h2>
		<div class="card">
<?php if ($stat['ursachen']): ?>
			<table class="bars">
				<tr><th scope="col">Ursache</th><th scope="col" class="num">St&ouml;rungen</th><th scope="col" class="bar"><span class="visually-hidden">Balken</span></th><th scope="col" class="num">Dauer</th></tr>
<?php foreach ($stat['ursachen'] as $u): ?>
				<tr>
					<th scope="row"><?= nb_e($u['name']) ?></th>
					<td class="num"><?= (int) $u['n'] ?></td>
					<td class="bar"><div class="bar__track" aria-hidden="true"><div class="bar__fill" data-w="<?= nb_bar_stufe($u['n'], $maxUrsache) ?>"></div></div></td>
					<td class="num"><?= nb_e(nb_dauer($u['dauer'])) ?></td>
				</tr>
<?php endforeach; ?>
			</table>
<?php else: ?>
			<p class="feed__empty">Noch keine St&ouml;rung erfasst.</p>
<?php endif; ?>
		</div>
		<p class="section__note">Quelle: St&ouml;rungsmeldungen der DB InfraGO auf strecken-info.de f&uuml;r die Strecken 1210, 1220, 1225, 1232 und 6100 im Abschnitt Hamburg &ndash; Itzehoe / Wrist. Die Dauer ist die Zeit, in der eine Meldung aktiv war, nicht die tats&auml;chliche Betriebsst&ouml;rung. Eilmeldungen der Nordbahn (Zugausf&auml;lle wegen Personal oder Fahrzeugen) z&auml;hlen hier nicht mit.</p>
	</section>
</main>

<?php $current = 'statistik'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
