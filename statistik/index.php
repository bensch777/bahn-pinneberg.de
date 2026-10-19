<?php
/**
 * Stoerungsstatistik aus dem Logbuch (cache/stoerungen-log.json): letzte 30
 * Tage, acht Kalenderwochen, haeufigste Ursachen. Rein server-seitig, eine
 * Farbe fuer die Balken, Zahlen als Text, Tabelle statt Grafik.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
require_once __DIR__ . '/../api/zuglog.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$data = nordbahn_dataset();
$stat = nb_statistik($data['stoerungen_verlauf'] ?? []);
$t30 = $stat['tage30'];
$maxWoche = max(1, max(array_column($stat['wochen'], 'n') ?: [0]));
$maxUrsache = max(1, max(array_column($stat['ursachen'], 'n') ?: [0]));

// Zuverlaessigkeit je Zug: Heimatbahnhof per ?bhf=, Hamburger Abfahrten (Hbf/Altona) je nach Ziel
$bhf = isset($_GET['bhf']) ? strtoupper(trim((string) $_GET['bhf'])) : NORDBAHN_HEIMAT_DEFAULT;
if (!isset(NORDBAHN_BAHNHOEFE[$bhf])) {
    $bhf = NORDBAHN_HEIMAT_DEFAULT;
}
$bhfName = NORDBAHN_BAHNHOEFE[$bhf]['name'];
$zugstat = zl_statistik();
$zugHin = $zugstat['zuege'][$bhf]['hin'] ?? [];
$zugRueck = [];
foreach (['AH', 'AA', 'ADF'] as $hh) {
    foreach ($zugstat['zuege'][$hh]['rueck'] ?? [] as $zug => $z) {
        // Nur Zuege, die den gewaehlten Bahnhof erreichen (Ast), sonst zaehlt Itzehoe-Zuege fuer Wrist mit
        $ast = NORDBAHN_BAHNHOEFE[$bhf]['ast'];
        if ($ast === 'itzehoe' && stripos($z['ziel'], 'Wrist') !== false) {
            continue;
        }
        if ($ast === 'wrist' && stripos($z['ziel'], 'Itzehoe') !== false) {
            continue;
        }
        $zugRueck[$hh . '|' . $zug] = $z;
    }
}
uasort($zugRueck, function ($a, $b) {
    return strcmp($a['plan'], $b['plan']);
});
$zugTage = (int) ($zugstat['beobachtete_tage'] ?? 0);

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
			<p class="page-head__lead">Wie zuverl&auml;ssig die Z&uuml;ge fahren und wie oft die Strecke Hamburg &ndash; Elmshorn &ndash; Itzehoe / Wrist gest&ouml;rt ist.</p>
		</div>
	</div>

<?php
/** Tabelle der Zuverlaessigkeit je Zug fuer eine Richtung. */
function nb_zug_tabelle(array $zuege, $mitAb)
{
    if (!$zuege) {
        return '<p class="feed__empty">Noch keine Beobachtungen.</p>';
    }
    $h = '<table class="bars bars--zuege"><tr><th scope="col">Zug</th>' . ($mitAb ? '<th scope="col">ab</th>' : '') . '<th scope="col" class="num">Tage</th><th scope="col" class="num">Ausf&auml;lle</th><th scope="col" class="bar"><span class="visually-hidden">Ausfallquote</span></th><th scope="col" class="num">&ge; 5 min</th><th scope="col" class="num">&Oslash; Versp.</th><th scope="col" class="num col-max">max.</th></tr>';
    foreach ($zuege as $z) {
        $wenig = $z['tage'] < NB_ZUGLOG_MIN_TAGE;
        $h .= '<tr' . ($wenig ? ' class="is-wenig"' : '') . '><th scope="row">' . nb_e($z['plan']) . ' ' . nb_e($z['linie']) . ' <span class="zug__ziel">' . nb_e($z['ziel']) . '</span></th>'
            . ($mitAb ? '<td>' . nb_e($z['ab']) . '</td>' : '')
            . '<td class="num">' . (int) $z['tage'] . '</td>'
            . '<td class="num">' . (int) $z['ausfaelle'] . ($z['teil'] ? ' <span class="zug__teil" title="Teilausf&auml;lle">+' . (int) $z['teil'] . '</span>' : '') . '</td>'
            . '<td class="bar"><div class="bar__track" aria-hidden="true"><div class="bar__fill bar__fill--rot' . ($z['ausfaelle'] ? '' : ' bar__fill--leer') . '" data-w="' . nb_bar_stufe($z['ausfaelle'], max(1, $z['tage'])) . '"></div></div></td>'
            . '<td class="num">' . (int) $z['spaet'] . '</td>'
            . '<td class="num">' . ($z['schnitt'] >= 0.05 ? '+' . nb_e(number_format($z['schnitt'], 1, ',', '')) : '0') . '</td>'
            . '<td class="num col-max">' . ($z['max'] ? '+' . (int) $z['max'] : '0') . '</td></tr>';
    }

    return $h . '</table>';
}
?>
	<section class="section" id="zuege">
		<div class="section__head">
			<h2 class="section__title">Zuverl&auml;ssigkeit je Zug, letzte <?= (int) NB_ZUGLOG_TAGE ?> Tage</h2>
		</div>
		<p class="section__note zug__wahl">Bahnhof:
<?php foreach (NORDBAHN_BAHNHOEFE as $id => $b): ?>
			<?php if ($id === $bhf): ?><strong><?= nb_e($b['name']) ?></strong><?php else: ?><a href="?bhf=<?= $id ?>#zuege"><?= nb_e($b['name']) ?></a><?php endif; ?>
<?php endforeach; ?>
		</p>
<?php if ($zugTage === 0): ?>
		<div class="card"><p class="feed__empty">Noch keine Daten, die ersten Werte gibt es nach <?= (int) NB_ZUGLOG_MIN_TAGE ?> Tagen.</p></div>
<?php else: ?>
		<h3 class="section__sub"><?= nb_e($bhfName) ?> &rarr; Hamburg</h3>
		<div class="card"><?= nb_zug_tabelle($zugHin, false) ?></div>
		<h3 class="section__sub">Hamburg &rarr; <?= nb_e($bhfName) ?></h3>
		<div class="card"><?= nb_zug_tabelle($zugRueck, true) ?></div>
<?php endif; ?>
		<p class="section__note">Ausf&auml;lle: ganz ausgefallen, +n teilweise (nur bis oder erst ab einem Unterwegsbahnhof). &ge; 5 min: Tage mit mindestens f&uuml;nf Minuten Versp&auml;tung. &Oslash; und max. ohne ausgefallene Tage. Graue Zeilen haben noch wenige Tage.</p>
	</section>

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
		<p class="section__note">Quelle: strecken-info.de der DB InfraGO, Abschnitt Hamburg &ndash; Itzehoe / Wrist. Die Dauer ist die Zeit, in der die Meldung aktiv war. Eilmeldungen der Nordbahn z&auml;hlen hier nicht mit.</p>
	</section>
</main>

<?php $current = 'statistik'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
