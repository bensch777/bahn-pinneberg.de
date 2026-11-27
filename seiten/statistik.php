<?php
/**
 * Statistik als Dashboard mit zwei Ansichten und einer Filterleiste:
 * "Zuege" (Zuverlaessigkeit im Pendelfenster aus dem Zuglog, filterbar nach
 * Bahnhof, Richtung und Zeitraum) und "Strecke" (Infrastrukturstoerungen aus
 * dem Logbuch, gleicher Zeitraum). Die Filter stehen in der Adresse, die Seite
 * rendert server-seitig; ein kleines Script schickt das Formular bei jeder
 * Aenderung ab und uebernimmt den Bahnhof von der Startseite.
 */

require __DIR__ . '/../inc/store.php';
require_once __DIR__ . '/../inc/render.php';
require __DIR__ . '/../inc/security.php';
require_once __DIR__ . '/../inc/zuglog.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../assets/css/dashboard.css');

$f = zl_filter($_GET);
$f['ansicht'] = ($_GET['ansicht'] ?? '') === 'strecke' ? 'strecke' : 'zuege';
$bhfName = NORDBAHN_BAHNHOEFE[$f['bhf']]['name'];

$data = nordbahn_dataset();
$stat = nb_statistik($data['stoerungen_verlauf'] ?? [], null, $f['tage']);
$sz = $stat['zeitraum'];
$maxWoche = max(1, max(array_column($stat['wochen'], 'n') ?: [0]));
$maxUrsache = max(1, max(array_column($stat['ursachen'], 'n') ?: [0]));
$dash = $f['ansicht'] === 'zuege' ? zl_dashboard(array_diff_key($f, ['ansicht' => 1])) : null;
$k = $dash['kennzahlen'] ?? null;
// Trend zum Vorzeitraum in Prozentpunkten; unter einem Punkt gilt er als gleich
$trend = null;
if ($k && $k['quote'] !== null && !empty($dash['vorher'])) {
    $diff = (int) round(100 * ($k['quote'] - $dash['vorher']['quote']));
    $trend = [
        'art'   => $diff > 0 ? 'gut' : ($diff < 0 ? 'schlecht' : 'gleich'),
        'text'  => $diff > 0 ? '▲ ' . $diff . ' Pkt.' : ($diff < 0 ? '▼ ' . abs($diff) . ' Pkt.' : '± 0 Pkt.'),
        'titel' => 'In den ' . $f['tage'] . ' Tagen davor ' . nb_prozent($dash['vorher']['quote']) . ' pünktlich (' . (int) $dash['vorher']['fahrten'] . ' Fahrten)',
    ];
}
// Bei beiden Richtungen getrennt: morgens hin, abends zurueck
$richtungen = [];
if ($k && $f['richtung'] === 'beide' && !empty($k['richtungen']['hin']['fahrten']) && !empty($k['richtungen']['rueck']['fahrten'])) {
    foreach (['hin' => 'Nach Hamburg', 'rueck' => 'Zurück'] as $dir => $name) {
        $r = $k['richtungen'][$dir];
        $richtungen[] = ['name' => $name, 'quote' => $r['puenktlich'] / $r['fahrten']];
    }
}
$fenster = ZL_PENDELFENSTER;
$uhr = function ($hhmm) { return (int) $hhmm; };
$fensterTeile = [];
if ($f['richtung'] !== 'rueck') {
    $fensterTeile[] = 'hin ' . $uhr($fenster['hin'][0]) . '–' . $uhr($fenster['hin'][1]) . ' Uhr';
}
if ($f['richtung'] !== 'hin') {
    $fensterTeile[] = 'zurück ' . $uhr($fenster['rueck'][0]) . '–' . $uhr($fenster['rueck'][1]) . ' Uhr';
}
$fensterText = 'Mo–Fr, ' . implode(', ', $fensterTeile);

$zeitraumText = 'letzte ' . $f['tage'] . ' Tage';
$title = 'Statistik Nordbahn RB60/RB61/RB71 – Pünktlichkeit und Störungen ab Pinneberg, Elmshorn, Itzehoe, Wrist';
$description = 'Wie pünktlich fahren RB60, RB61 und RB71 ab ' . $bhfName . '? Ausfälle, Verspätungen und Störungen der letzten '
    . $f['tage'] . ' Tage im Berufsverkehr, die unzuverlässigsten und zuverlässigsten Züge. Dazu ' . $sz['n'] . ' Infrastruktur-Störung'
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

/** Auswahlfeld der Filterleiste als Chip; die Beschriftung ist nur fuer Screenreader da, die Auswahl spricht fuer sich. */
function nb_filter_select($name, $label, array $optionen, $wert)
{
    $h = '<label class="chip"><span class="visually-hidden">' . nb_e($label) . '</span><select class="chip__select" name="' . nb_e($name) . '">';
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
<?= nb_theme_head() ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="Bahn-Pinneberg.de">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/statistik/">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= SITE_URL ?>/assets/og/info.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>assets/icons/icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>assets/icons/icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css?v=<?= $assetVersion ?>">
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'statistik') ?>

<main class="wrap">
	<div class="page-head page-head--statistik">
		<div>
			<h1>Statistik</h1>
<?php if ($f['ansicht'] === 'zuege'): ?>
			<p class="page-head__lead">Wie verl&auml;sslich das Pendeln im Berufsverkehr ist.</p>
<?php else: ?>
			<p class="page-head__lead">Wie oft die Strecke Hamburg &ndash; Elmshorn &ndash; Itzehoe / Wrist gest&ouml;rt ist.</p>
<?php endif; ?>
		</div>
		<nav class="umschalter" aria-label="Ansicht">
			<a class="umschalter__wahl" href="<?= nb_e(nb_statistik_url($f, ['ansicht' => 'zuege'])) ?>"<?= $f['ansicht'] === 'zuege' ? ' aria-current="page"' : '' ?>>Z&uuml;ge</a>
			<a class="umschalter__wahl" href="<?= nb_e(nb_statistik_url($f, ['ansicht' => 'strecke'])) ?>"<?= $f['ansicht'] === 'strecke' ? ' aria-current="page"' : '' ?>>Strecke</a>
		</nav>
	</div>

	<form class="chips<?= $f['ansicht'] === 'zuege' && !in_array($f['bhf'], ZL_DB_BAHNHOEFE, true) ? ' chips--drei' : '' ?>" id="filter" method="get" action="./" data-bhf-gesetzt="<?= isset($_GET['bhf']) ? '1' : '' ?>">
<?php if ($f['ansicht'] === 'strecke'): ?>
		<input type="hidden" name="ansicht" value="strecke">
<?php endif; ?>
<?php if ($f['ansicht'] === 'zuege'): ?>
		<?= nb_filter_select('bhf', 'Bahnhof', array_map(function ($b) { return $b['name']; }, NORDBAHN_BAHNHOEFE), $f['bhf']) ?>
<?php if (in_array($f['bhf'], ZL_DB_BAHNHOEFE, true)): ?>
		<?= nb_filter_select('betreiber', 'Züge', ['' => 'Nordbahn', 'db' => 'DB Regio'], $f['betreiber'] === 'db' ? 'db' : '') ?>
<?php endif; ?>
		<?= nb_filter_select('richtung', 'Richtung', ['beide' => 'Hin & zurück', 'hin' => 'Nach Hamburg', 'rueck' => 'Zurück'], $f['richtung']) ?>
<?php else: ?>
		<input type="hidden" name="bhf" value="<?= nb_e($f['bhf']) ?>">
		<input type="hidden" name="betreiber" value="<?= $f['betreiber'] === 'db' ? 'db' : '' ?>">
		<input type="hidden" name="richtung" value="<?= nb_e($f['richtung']) ?>">
<?php endif; ?>
		<?= nb_filter_select('tage', 'Zeitraum', [7 => '7 Tage', 14 => '14 Tage', 30 => '30 Tage', 60 => '60 Tage'], $f['tage']) ?>
		<button type="submit" class="chips__los">Anzeigen</button>
	</form>

<?php if ($f['ansicht'] === 'zuege'): ?>
	<section class="card kpi<?= $richtungen ? ' kpi--richtungen' : '' ?>" aria-label="Kennzahlen">
		<div class="kpi__zelle kpi__zelle--haupt">
			<p class="kpi__label">P&uuml;nktlich</p>
			<p class="kpi__wert"><?= nb_e(nb_prozent($k['quote'])) ?></p>
<?php if ($trend): ?>
			<p class="kpi__note"><span class="trend trend--<?= $trend['art'] ?>" title="<?= nb_e($trend['titel']) ?>"><?= nb_e($trend['text']) ?></span> vorher <?= nb_e(nb_prozent($dash['vorher']['quote'])) ?></p>
<?php endif; ?>
			<p class="kpi__note"><?= (int) $k['puenktlich'] ?> von <?= (int) $k['fahrten'] ?> Fahrten</p>
		</div>
<?php if ($richtungen): ?>
		<div class="kpi__zelle kpi__zelle--richtungen">
<?php foreach ($richtungen as $r): ?>
			<div class="kpi-richtung">
				<span class="kpi-richtung__name"><?= nb_e($r['name']) ?></span>
				<span class="kpi-richtung__wert"><?= nb_e(nb_prozent($r['quote'])) ?></span>
				<span class="bar__track kpi-richtung__balken kpi-richtung__balken--<?= $r['quote'] >= 0.9 ? 'gut' : ($r['quote'] >= 0.75 ? 'mittel' : 'schlecht') ?>" aria-hidden="true"><span class="bar__fill" data-w="<?= nb_bar_stufe($r['quote'], 1) ?>"></span></span>
			</div>
<?php endforeach; ?>
		</div>
<?php endif; ?>
		<div class="kpi__zelle">
			<p class="kpi__label">Ausgefallen</p>
			<p class="kpi__wert kpi__wert--klein"><?= (int) $k['ausfall'] ?></p>
			<p class="kpi__note"><?= nb_e(nb_prozent($k['fahrten'] ? $k['ausfall'] / $k['fahrten'] : null)) ?><?= $k['teil'] ? ' · ' . (int) $k['teil'] . ' teilweise' : ' der Fahrten' ?></p>
		</div>
		<div class="kpi__zelle">
			<p class="kpi__label">&Oslash; Versp&auml;tung</p>
			<p class="kpi__wert kpi__wert--klein"><?= $k['fahrten'] ? '+' . nb_e(number_format($k['schnitt'], 1, ',', '')) . '<small> Min.</small>' : '–' ?></p>
			<p class="kpi__note">ohne Ausf&auml;lle</p>
		</div>
	</section>

	<section class="section">
		<div class="section__head">
			<h2 class="section__title">Unp&uuml;nktlich je Werktag</h2>
			<ul class="legende" aria-label="Legende">
				<li><span class="legende__punkt legende__punkt--spaet"></span>Ab <?= (int) NB_ZUG_SPAET ?> Min. versp&auml;tet</li>
				<li><span class="legende__punkt legende__punkt--ausfall"></span>Ausgefallen</li>
			</ul>
		</div>
		<div class="card verlauf-karte">
			<?= nb_render_zug_verlauf($dash['verlauf'], $fensterText) ?>
		</div>
	</section>

	<div class="top-raster" id="top">
		<div class="top-schalter umschalter" role="group" aria-label="Rangliste" hidden>
			<button type="button" class="umschalter__wahl" data-top="unzuverlaessig" aria-pressed="true">Unzuverl&auml;ssigste</button>
			<button type="button" class="umschalter__wahl" data-top="zuverlaessig" aria-pressed="false">Zuverl&auml;ssigste</button>
		</div>
		<section class="section top-liste" data-top-liste="unzuverlaessig">
			<h2 class="section__title">Unzuverl&auml;ssigste Z&uuml;ge</h2>
			<div class="card"><?= nb_render_zug_top($dash['top']['unzuverlaessig']) ?></div>
		</section>
		<section class="section top-liste" data-top-liste="zuverlaessig">
			<h2 class="section__title">Zuverl&auml;ssigste Z&uuml;ge</h2>
			<div class="card"><?= nb_render_zug_top($dash['top']['zuverlaessig']) ?></div>
		</section>
	</div>
<?php else: ?>
	<section class="card kpi kpi--vier" aria-label="Kennzahlen">
		<div class="kpi__zelle"><p class="kpi__label">St&ouml;rungen</p><p class="kpi__wert kpi__wert--klein"><?= (int) $sz['n'] ?></p></div>
		<div class="kpi__zelle"><p class="kpi__label">Gesamtdauer</p><p class="kpi__wert kpi__wert--klein"><?= $sz['n'] ? nb_e(nb_dauer($sz['dauer'])) : '–' ?></p></div>
		<div class="kpi__zelle"><p class="kpi__label">Dauer im Schnitt</p><p class="kpi__wert kpi__wert--klein"><?= $sz['n'] ? nb_e(nb_dauer($sz['schnitt'])) : '–' ?></p></div>
		<div class="kpi__zelle"><p class="kpi__label">L&auml;ngste St&ouml;rung</p><p class="kpi__wert kpi__wert--klein"><?= $sz['laengste'] ? nb_e(nb_dauer($sz['laengste']['dauer'])) : '–' ?></p><?php if ($sz['laengste']): ?><p class="kpi__note"><?= nb_e($sz['laengste']['titel']) ?>, <?= nb_e(date('d.m.', $sz['laengste']['start'])) ?></p><?php endif; ?></div>
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
	form.querySelector('.chips__los').hidden = true;
	form.addEventListener('change', function () {
		// Leere Werte nicht in die Adresse
		Array.prototype.forEach.call(form.elements, function (el) { if (el.name && el.value === '') el.disabled = true; });
		form.submit();
	});
})();
(function () {
	// Handy: nur eine Rangliste, umschaltbar; ohne Script stehen beide untereinander
	var raster = document.getElementById('top');
	if (!raster) return;
	var schalter = raster.querySelector('.top-schalter');
	var knoepfe = schalter.querySelectorAll('button');
	function waehlen(wahl) {
		raster.setAttribute('data-wahl', wahl);
		Array.prototype.forEach.call(knoepfe, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-top') === wahl ? 'true' : 'false'); });
	}
	schalter.hidden = false;
	raster.classList.add('top-raster--js');
	waehlen('unzuverlaessig');
	schalter.addEventListener('click', function (e) {
		var b = e.target.closest('button');
		if (b) waehlen(b.getAttribute('data-top'));
	});
})();
(function () {
	// Tooltip am Verlauf: Maus zeigt den naechsten Tag, Tippen schaltet ihn um, Pfeiltasten wandern
	var chart = document.querySelector('.verlauf');
	if (!chart) return;
	var karte = chart.parentNode;
	var tage = Array.prototype.slice.call(chart.querySelectorAll('.verlauf__tag'));
	if (!tage.length) return;
	tage.forEach(function (t) { t.removeAttribute('title'); });
	chart.classList.add('verlauf--js');
	chart.tabIndex = 0;
	var tip = document.createElement('div');
	tip.className = 'verlauf-tip';
	tip.hidden = true;
	tip.setAttribute('role', 'status');
	karte.appendChild(tip);
	var aktiv = -1;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}
	function zeile(art, label, wert, n) {
		var z = el('span', 'verlauf-tip__zeile');
		z.appendChild(el('span', 'legende__punkt legende__punkt--' + art));
		z.appendChild(el('span', '', label));
		z.appendChild(el('b', '', wert + (n && wert ? ' · ' + Math.round(100 * wert / n) + ' %' : '')));
		return z;
	}
	function zeigen(i) {
		if (i < 0 || i >= tage.length) return;
		var t = tage[i], d = t.dataset;
		if (aktiv >= 0) tage[aktiv].classList.remove('verlauf__tag--aktiv');
		aktiv = i;
		t.classList.add('verlauf__tag--aktiv');
		chart.classList.add('verlauf--fokus');
		tip.textContent = '';
		tip.appendChild(el('span', 'verlauf-tip__tag', d.tag || ''));
		if (d.leer) {
			tip.appendChild(el('span', 'verlauf-tip__fuss', d.leer));
		} else {
			var n = +d.n, s = +d.spaet, a = +d.ausfall;
			tip.appendChild(zeile('spaet', 'Verspätet', s, n));
			tip.appendChild(zeile('ausfall', 'Ausgefallen', a, n));
			tip.appendChild(el('span', 'verlauf-tip__fuss', (n - s - a) + ' von ' + n + (n === 1 ? ' Fahrt' : ' Fahrten') + ' pünktlich'));
		}
		tip.hidden = false;
		// Neben die Saeule, damit sie sichtbar bleibt; am rechten Rand links davon
		var kr = karte.getBoundingClientRect(), tr = t.getBoundingClientRect(), cr = chart.getBoundingClientRect();
		var w = tip.offsetWidth, x = tr.right - kr.left + 8;
		if (x + w > kr.width - 4) x = tr.left - kr.left - 8 - w;
		tip.style.left = Math.max(4, x) + 'px';
		tip.style.top = (cr.top - kr.top + 4) + 'px';
	}
	function weg() {
		if (aktiv >= 0) tage[aktiv].classList.remove('verlauf__tag--aktiv');
		aktiv = -1;
		chart.classList.remove('verlauf--fokus');
		tip.hidden = true;
	}
	function naechster(x) {
		var best = 0, abst = Infinity;
		tage.forEach(function (t, i) {
			var r = t.getBoundingClientRect(), m = Math.abs(r.left + r.width / 2 - x);
			if (m < abst) { abst = m; best = i; }
		});
		return best;
	}
	chart.addEventListener('pointermove', function (e) { if (e.pointerType === 'mouse') zeigen(naechster(e.clientX)); });
	chart.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') weg(); });
	chart.addEventListener('pointerdown', function (e) {
		if (e.pointerType === 'mouse') return;
		var i = naechster(e.clientX);
		if (i === aktiv) weg(); else zeigen(i);
	});
	document.addEventListener('pointerdown', function (e) { if (aktiv >= 0 && !chart.contains(e.target)) weg(); });
	chart.addEventListener('keydown', function (e) {
		if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
			e.preventDefault();
			zeigen(aktiv < 0 ? (e.key === 'ArrowRight' ? 0 : tage.length - 1) : Math.min(tage.length - 1, Math.max(0, aktiv + (e.key === 'ArrowRight' ? 1 : -1))));
		} else if (e.key === 'Escape') weg();
	});
	chart.addEventListener('blur', weg);
	window.addEventListener('resize', function () { if (aktiv >= 0) zeigen(aktiv); });
})();
</script>
</body>
</html>
