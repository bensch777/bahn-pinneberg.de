<?php
/**
 * Statistik als Dashboard mit zwei Ansichten und einer Filterleiste:
 * "Zuege" (Zuverlaessigkeit aus dem Zuglog, filterbar nach Bahnhof, Richtung,
 * Zeitraum, Tageszeit und Werktagen) und "Strecke" (Infrastrukturstoerungen aus
 * dem Logbuch, gleicher Zeitraum). Die Filter stehen in der Adresse, die Seite
 * rendert server-seitig; ein kleines Script schickt das Formular bei jeder
 * Aenderung ab und uebernimmt den Bahnhof von der Startseite.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
require_once __DIR__ . '/../api/zuglog.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$f = zl_filter($_GET);
$f['ansicht'] = ($_GET['ansicht'] ?? '') === 'strecke' ? 'strecke' : 'zuege';
$f['sort'] = ($_GET['sort'] ?? '') === 'ausfall' ? 'ausfall' : 'zeit';
$bhfName = NORDBAHN_BAHNHOEFE[$f['bhf']]['name'];

$data = nordbahn_dataset();
$stat = nb_statistik($data['stoerungen_verlauf'] ?? [], null, $f['tage']);
$sz = $stat['zeitraum'];
$maxWoche = max(1, max(array_column($stat['wochen'], 'n') ?: [0]));
$maxUrsache = max(1, max(array_column($stat['ursachen'], 'n') ?: [0]));
$dash = $f['ansicht'] === 'zuege' ? zl_dashboard(array_diff_key($f, ['ansicht' => 1, 'sort' => 1])) : null;
$k = $dash['kennzahlen'] ?? null;
$sortLinks = [nb_statistik_url($f, ['sort' => 'zeit']), nb_statistik_url($f, ['sort' => 'ausfall']), $f['sort']];

$zeitraumText = 'letzte ' . $f['tage'] . ' Tage';
$title = 'Statistik Nordbahn RB60/RB61/RB71 – Pünktlichkeit und Störungen ab Pinneberg, Elmshorn, Itzehoe, Wrist';
$description = 'Wie pünktlich fahren RB60, RB61 und RB71 ab ' . $bhfName . '? Ausfälle, Verspätungen und Störungen der letzten '
    . $f['tage'] . ' Tage, filterbar nach Bahnhof, Richtung, Tageszeit und Werktagen. Dazu ' . $sz['n'] . ' Infrastruktur-Störung'
    . ($sz['n'] === 1 ? '' : 'en') . ' auf der Strecke.';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Dataset',
    'name' => 'Pünktlichkeit und Störungen auf der Nordbahn-Strecke Hamburg – Elmshorn – Itzehoe/Wrist',
    'description' => $description,
    'url' => SITE_URL . '/statistik/',
    'inLanguage' => 'de',
    'isAccessibleForFree' => true,
    'creator' => ['@type' => 'Person', 'name' => 'Benjamin Neske', 'url' => 'https://bensch777.de/'],
    'temporalCoverage' => $stat['seit'] ? date('Y-m-d', $stat['seit']) . '/..' : '',
    'spatialCoverage' => 'Hamburg – Pinneberg – Elmshorn – Itzehoe / Wrist',
];

/** Auswahlfeld der Filterleiste. */
function nb_filter_select($name, $label, array $optionen, $wert)
{
    $h = '<label class="filter"><span class="filter__label">' . $label . '</span><select class="heimat__select" name="' . nb_e($name) . '">';
    foreach ($optionen as $v => $text) {
        $h .= '<option value="' . nb_e($v) . '"' . ((string) $v === (string) $wert ? ' selected' : '') . '>' . nb_e($text) . '</option>';
    }

    return $h . '</select></label>';
}
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
<meta property="og:site_name" content="Bahn-Pinneberg.de">
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
		<a class="site-head__brand" href="<?= $root ?>">Bahn-Pinneberg.de</a>
		<nav class="site-head__tools" aria-label="Zur&uuml;ck">
			<?= nb_render_zurueck($root) ?>
		</nav>
	</div>
</header>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>Statistik</h1>
			<p class="page-head__lead">Wie p&uuml;nktlich die Z&uuml;ge fahren und wie oft die Strecke Hamburg &ndash; Elmshorn &ndash; Itzehoe / Wrist gest&ouml;rt ist.</p>
		</div>
	</div>

	<nav class="tabs" aria-label="Ansicht">
		<a class="tabs__tab" href="<?= nb_e(nb_statistik_url($f, ['ansicht' => 'zuege'])) ?>"<?= $f['ansicht'] === 'zuege' ? ' aria-current="page"' : '' ?>>Z&uuml;ge</a>
		<a class="tabs__tab" href="<?= nb_e(nb_statistik_url($f, ['ansicht' => 'strecke'])) ?>"<?= $f['ansicht'] === 'strecke' ? ' aria-current="page"' : '' ?>>Strecke</a>
	</nav>

	<form class="filters" id="filter" method="get" action="./" data-bhf-gesetzt="<?= isset($_GET['bhf']) ? '1' : '' ?>">
<?php if ($f['ansicht'] === 'strecke'): ?>
		<input type="hidden" name="ansicht" value="strecke">
<?php endif; ?>
<?php if ($f['ansicht'] === 'zuege'): ?>
		<?= nb_filter_select('bhf', 'Bahnhof', array_map(function ($b) { return $b['name']; }, NORDBAHN_BAHNHOEFE), $f['bhf']) ?>
		<?= nb_filter_select('richtung', 'Richtung', ['beide' => 'Beide Richtungen', 'hin' => 'Nach Hamburg', 'rueck' => 'Zurück'], $f['richtung']) ?>
<?php else: ?>
		<input type="hidden" name="bhf" value="<?= nb_e($f['bhf']) ?>">
		<input type="hidden" name="richtung" value="<?= nb_e($f['richtung']) ?>">
<?php endif; ?>
		<?= nb_filter_select('tage', 'Zeitraum', [7 => '7 Tage', 14 => '14 Tage', 30 => '30 Tage', 60 => '60 Tage'], $f['tage']) ?>
<?php if ($f['ansicht'] === 'zuege'): ?>
		<?= nb_filter_select('zeit', 'Tageszeit', ['alle' => 'Ganzer Tag', 'morgens' => 'Morgens 5–10 Uhr', 'abends' => 'Abends 15–20 Uhr'], $f['zeit']) ?>
		<?= nb_filter_select('werktage', 'Tage', ['' => 'Alle Tage', '1' => 'Mo–Fr'], $f['werktage'] ? '1' : '') ?>
<?php else: ?>
		<input type="hidden" name="zeit" value="<?= nb_e($f['zeit']) ?>">
		<input type="hidden" name="werktage" value="<?= $f['werktage'] ? '1' : '' ?>">
<?php endif; ?>
		<button type="submit" class="filters__los">Anzeigen</button>
	</form>

<?php if ($f['ansicht'] === 'zuege'): ?>
	<section class="section" aria-label="Kennzahlen">
		<div class="stats">
			<div class="card stat"><p class="stat__label">P&uuml;nktlich</p><p class="stat__value"><?= nb_e(nb_prozent($k['quote'])) ?></p><p class="stat__note"><?= (int) $k['puenktlich'] ?> von <?= (int) $k['fahrten'] ?> Fahrten unter <?= (int) NB_ZUG_SPAET ?> Min.</p></div>
			<div class="card stat"><p class="stat__label">Ausgefallen</p><p class="stat__value"><?= (int) $k['ausfall'] ?></p><p class="stat__note"><?= nb_e(nb_prozent($k['fahrten'] ? $k['ausfall'] / $k['fahrten'] : null)) ?><?= $k['teil'] ? ', davon ' . (int) $k['teil'] . ' teilweise' : '' ?></p></div>
			<div class="card stat"><p class="stat__label">&Oslash; Versp&auml;tung</p><p class="stat__value"><?= $k['fahrten'] ? '+' . nb_e(number_format($k['schnitt'], 1, ',', '')) . ' Min.' : '–' ?></p><p class="stat__note">ohne ausgefallene Fahrten</p></div>
			<div class="card stat"><p class="stat__label">Beobachtet</p><p class="stat__value"><?= (int) $k['tage_beobachtet'] ?> von <?= (int) $f['tage'] ?></p><p class="stat__note">Tagen</p></div>
		</div>
	</section>

	<section class="section">
		<div class="section__head">
			<h2 class="section__title">Verlauf je Tag</h2>
			<ul class="legende" aria-label="Legende">
				<li><span class="legende__punkt legende__punkt--puenktlich"></span>P&uuml;nktlich</li>
				<li><span class="legende__punkt legende__punkt--spaet"></span>Ab <?= (int) NB_ZUG_SPAET ?> Min. versp&auml;tet</li>
				<li><span class="legende__punkt legende__punkt--ausfall"></span>Ausgefallen</li>
			</ul>
		</div>
		<div class="card verlauf-karte">
			<?= nb_render_zug_verlauf($dash['verlauf']) ?>
			<details class="verlauf__zahlen"><summary>Zahlen je Tag</summary><?= nb_render_zug_verlauf_tabelle($dash['verlauf']) ?></details>
		</div>
	</section>

<?php if ($f['richtung'] !== 'rueck'): ?>
	<section class="section">
		<h2 class="section__title"><?= nb_e($bhfName) ?> &rarr; Hamburg</h2>
		<div class="card"><?= nb_render_zug_tabelle($dash['hin'], false, $sortLinks) ?></div>
	</section>
<?php endif; ?>
<?php if ($f['richtung'] !== 'hin'): ?>
	<section class="section">
		<h2 class="section__title">Hamburg &rarr; <?= nb_e($bhfName) ?></h2>
		<div class="card"><?= nb_render_zug_tabelle($dash['rueck'], true, $sortLinks) ?></div>
	</section>
<?php endif; ?>
	<p class="section__note">P&uuml;nktlich hei&szlig;t unter <?= (int) NB_ZUG_SPAET ?> Minuten Versp&auml;tung, ausgefallen schlie&szlig;t Teilausf&auml;lle ein (nur bis oder erst ab einem Unterwegsbahnhof, in der Tabelle als +n). &Oslash; und max. ohne ausgefallene Fahrten. Beobachtet wird, was abgefragt wird: Prisdorf laufend, andere Bahnh&ouml;fe nur, solange jemand die Seite mit diesem Bahnhof offen hat. Graue Zeilen haben weniger als <?= (int) NB_ZUGLOG_MIN_TAGE ?> Tage.</p>
<?php else: ?>
	<section class="section" aria-label="Kennzahlen">
		<div class="stats">
			<div class="card stat"><p class="stat__label">St&ouml;rungen</p><p class="stat__value"><?= (int) $sz['n'] ?></p></div>
			<div class="card stat"><p class="stat__label">Gesamtdauer</p><p class="stat__value"><?= $sz['n'] ? nb_e(nb_dauer($sz['dauer'])) : '–' ?></p></div>
			<div class="card stat"><p class="stat__label">Dauer im Schnitt</p><p class="stat__value"><?= $sz['n'] ? nb_e(nb_dauer($sz['schnitt'])) : '–' ?></p></div>
			<div class="card stat"><p class="stat__label">L&auml;ngste St&ouml;rung</p><p class="stat__value"><?= $sz['laengste'] ? nb_e(nb_dauer($sz['laengste']['dauer'])) : '–' ?></p><?php if ($sz['laengste']): ?><p class="stat__note"><?= nb_e($sz['laengste']['titel']) ?>, <?= nb_e(date('d.m.', $sz['laengste']['start'])) ?></p><?php endif; ?></div>
		</div>
	</section>

	<section class="section">
		<h2 class="section__title">Nach Kalenderwoche</h2>
		<div class="card">
			<table class="bars bars--kompakt">
				<tr><th scope="col">Woche</th><th scope="col" class="col-zeitraum">Zeitraum</th><th scope="col" class="num">St&ouml;rungen</th><th scope="col" class="bar"><span class="visually-hidden">Balken</span></th><th scope="col" class="num">Dauer</th></tr>
<?php foreach ($stat['wochen'] as $w): ?>
				<tr>
					<th scope="row">KW <?= (int) $w['kw'] ?></th>
					<td class="col-zeitraum"><?= nb_e(date('d.m.', $w['von'])) ?> &ndash; <?= nb_e(date('d.m.', $w['bis'])) ?></td>
					<td class="num"><?= (int) $w['n'] ?></td>
					<td class="bar"><div class="bar__track" aria-hidden="true"><div class="bar__fill<?= $w['n'] ? '' : ' bar__fill--leer' ?>" data-w="<?= nb_bar_stufe($w['n'], $maxWoche) ?>"></div></div></td>
					<td class="num"><?= $w['n'] ? nb_e(nb_dauer($w['dauer'])) : '–' ?></td>
				</tr>
<?php endforeach; ?>
			</table>
		</div>
	</section>

	<section class="section">
		<h2 class="section__title">H&auml;ufigste Ursachen</h2>
		<div class="card">
<?php if ($stat['ursachen']): ?>
			<table class="bars bars--kompakt">
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
			<p class="feed__empty">Keine St&ouml;rung im Zeitraum.</p>
<?php endif; ?>
		</div>
		<p class="section__note">Quelle: strecken-info.de der DB InfraGO, Abschnitt Hamburg &ndash; Itzehoe / Wrist. Die Dauer ist die Zeit, in der die Meldung aktiv war. Eilmeldungen der Nordbahn z&auml;hlen hier nicht mit. Alle St&ouml;rungen einzeln stehen unter <a href="<?= $root ?>stoerungen/">St&ouml;rungen</a>.</p>
	</section>
<?php endif; ?>
</main>

<?php $current = 'statistik'; require __DIR__ . '/../inc/footer.php'; ?>
<script nonce="<?= nb_csp_nonce() ?>">
(function () {
	var form = document.getElementById('filter');
	if (!form) return;
	// Ohne Bahnhof in der Adresse den Bahnhof von der Startseite nehmen
	var sel = form.querySelector('select[name="bhf"]');
	if (sel && !form.getAttribute('data-bhf-gesetzt')) {
		var heimat = null;
		try { heimat = localStorage.getItem('nordbahn.heimat'); } catch (e) { /* privater Modus */ }
		if (heimat && heimat !== sel.value && sel.querySelector('option[value="' + heimat + '"]')) { sel.value = heimat; form.submit(); return; }
	}
	// Jede Aenderung schickt das Formular ab; der Knopf bleibt nur ohne Script
	form.querySelector('.filters__los').hidden = true;
	form.addEventListener('change', function () {
		// Leere Werte nicht in die Adresse
		Array.prototype.forEach.call(form.elements, function (el) { if (el.name && el.value === '') el.disabled = true; });
		form.submit();
	});
})();
</script>
</body>
</html>
