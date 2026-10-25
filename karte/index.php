<?php
/**
 * Live-Karte: wo die Zuege der RB60, RB61 und RB71 gerade sind. Die Positionen
 * rechnet api/karte.php aus dem Stationsmonitor der Nordbahn (Laufweg mit
 * Prognose je Halt), es gibt keine GPS-Ortung. Die Seite rendert die Liste aus
 * dem letzten Stand im Cache (ohne selbst abzufragen), karte.js zeichnet die
 * Karte mit Leaflet (vendor/leaflet, selbst gehostet) und OpenStreetMap-Kacheln,
 * holt alle 30 Sekunden einen frischen Stand von api/karte und bewegt die Zuege
 * dazwischen nach ihren Ist-Zeiten weiter. Der Kartenausschnitt richtet sich nach dem
 * Heimatbahnhof der Startseite und laesst sich im Auswahlfeld wechseln.
 *
 * Die Kacheln sind die einzige fremde Quelle der Seite; die Content-Security-
 * Policy bekommt dafuer tile.openstreetmap.org in img-src (inc/security.php),
 * die Datenschutzerklaerung nennt den Dienst.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
define('NORDBAHN_KARTE_LIB', true);
require_once __DIR__ . '/../api/karte.php';
nb_send_security_headers(['img-src' => 'https://tile.openstreetmap.org']);

define('SITE_URL', nordbahn_site_url());
$root = '../';
$leafletVersion = '1.9.4';
$assetVersion = max((int) @filemtime(__DIR__ . '/../dashboard.css'), (int) @filemtime(__DIR__ . '/../karte.js'));
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$strecke = ka_strecke();
$linien = is_array($strecke['linien'] ?? null) ? $strecke['linien'] : [];
$ansichten = is_array($strecke['ansichten'] ?? null) ? $strecke['ansichten'] : [];
// Reihenfolge im Auswahlfeld: die Abschnitte zuerst, das Gesamtnetz zuletzt
$ansichtReihe = array_merge(array_diff(array_keys($ansichten), ['netz']), isset($ansichten['netz']) ? ['netz'] : []);
// Vorbelegung aus dem Heimatbahnhof der Startseite (localStorage, siehe karte.js): Stamm -> Hamburg - Elmshorn, Aeste -> ihr Abschnitt
$heimatAnsicht = [];
foreach (NORDBAHN_BAHNHOEFE as $id => $bhf) {
    $heimatAnsicht[$id] = $bhf['ast'] === 'stamm' ? 'hamburg' : $bhf['ast'];
}
// Nur lesen: die Seite fragt den Monitor nicht selbst, das macht api/karte auf Anfrage des Scripts
$stand = nordbahn_read_json(KA_CACHE);
$zuege = is_array($stand) && is_array($stand['zuege'] ?? null) ? $stand['zuege'] : [];
$standJson = json_encode($stand ?? ka_leer(), $jsonFlags) ?: 'null';
$streckeJson = json_encode($strecke, $jsonFlags) ?: '{}';

$title = 'Nordbahn live – Zugkarte RB60, RB61 und RB71 zwischen Hamburg, Elmshorn, Itzehoe und Wrist';
$description = 'Wo die Züge der Nordbahn gerade sind: Live-Karte der RB60, RB61 und RB71 mit berechneten Positionen aus dem Stationsmonitor, Verspätung und nächstem Halt je Zug. Keine GPS-Ortung, Stand jede Minute.';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Nordbahn live – Zugkarte',
    'description' => $description,
    'url' => SITE_URL . '/karte/',
    'inLanguage' => 'de',
    'isPartOf' => ['@id' => SITE_URL . '/#website'],
];

/** Klasse fuer die Linienfarbe: "RB 71" oder "RB71" -> "rb71". */
function nb_karte_linie_klasse($linie)
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $linie));
}

/** Chip fuer die Verspaetung: ab 3 Minuten gelb, ab 10 rot, sonst leer. */
function nb_karte_delay_pill($min)
{
    $min = (int) $min;
    if ($min < 3) {
        return '';
    }

    return '<span class="pill ' . ($min >= 10 ? 'pill--ausfall' : 'pill--teil') . '">+' . $min . '</span>';
}

/** Liste der Zuege wie sie karte.js auch baut; Stand aus dem Cache. */
function nb_karte_liste(array $zuege)
{
    if (!$zuege) {
        return '<p class="zugliste__leer">Gerade kein Zug auf der Karte.</p>';
    }
    $h = '<ul class="zugliste">';
    foreach ($zuege as $z) {
        $h .= '<li><span class="linie linie--' . nb_e(nb_karte_linie_klasse($z['linie'] ?? '')) . '">' . nb_e($z['linie'] ?? '') . '</span>'
            . '<span class="zugliste__text"><strong>' . nb_e($z['von'] ?? '') . ' &rarr; ' . nb_e($z['nach'] ?? '') . '</strong>'
            . '<span class="zugliste__lage">' . nb_e($z['lage']['text'] ?? '') . ($z['hinweis'] !== '' ? ' &middot; ' . nb_e($z['hinweis']) : '') . '</span></span>'
            . nb_karte_delay_pill($z['verspaetung'] ?? 0) . '</li>';
    }

    return $h . '</ul>';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/karte/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Bahn-Pinneberg.de">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/karte/">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<meta property="og:image" content="<?= SITE_URL ?>/og/karte.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Nordbahn live: Zugkarte der RB60, RB61 und RB71">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= nb_e($title) ?>">
<meta name="twitter:description" content="<?= nb_e($description) ?>">
<meta name="twitter:image" content="<?= SITE_URL ?>/og/karte.png">
<link rel="icon" type="image/svg+xml" href="<?= $root ?>favicon.svg">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png">
<link rel="stylesheet" href="<?= $root ?>vendor/leaflet/leaflet.css?v=<?= $leafletVersion ?>">
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

<main class="wrap" id="karte-app" data-endpoint="<?= $root ?>api/karte" data-zug-bild="<?= $root ?>karte/zug.png?v=<?= (int) @filemtime(__DIR__ . '/zug.png') ?>">
	<div class="page-head">
		<div>
			<h1>Nordbahn live</h1>
			<p class="page-head__lead">RB60 &middot; RB61 &middot; RB71 &ndash; wo die Z&uuml;ge gerade sind. Berechnete Positionen aus dem Stationsmonitor der Nordbahn, keine GPS-Ortung.</p>
		</div>
		<p class="freshness<?= $zuege ? '' : ' is-empty' ?>" id="karte-freshness" aria-live="polite"><span class="dot"></span><span id="karte-freshness-text"><?= is_array($stand) && !empty($stand['fetched_at']) ? 'Letzter Abruf ' . nb_e(nb_fmt_uhr(nb_ts($stand['fetched_at']))) : 'Noch kein Stand' ?></span></p>
	</div>

	<div class="karte-status" id="karte-status">
		<p class="karte-status__anzahl"><strong id="karte-anzahl"><?= count($zuege) ?></strong> <span id="karte-anzahl-text"><?= count($zuege) === 1 ? 'Zug' : 'Züge' ?> dargestellt</span></p>
		<label class="heimat">
			<span class="heimat__label">Ausschnitt</span>
			<select id="ansicht" class="heimat__select" data-heimat-ansicht="<?= nb_e(json_encode($heimatAnsicht, JSON_UNESCAPED_UNICODE)) ?>">
<?php foreach ($ansichtReihe as $id): ?>
				<option value="<?= nb_e($id) ?>"><?= nb_e($ansichten[$id]['name'] ?? $id) ?></option>
<?php endforeach; ?>
			</select>
		</label>
	</div>

	<div class="karte" id="karte" role="region" aria-label="Karte mit den Zugpositionen">
		<noscript><p class="karte__leer">Die Karte braucht JavaScript. Die Liste darunter zeigt den letzten Stand.</p></noscript>
		<p class="karte__leer" id="karte-leer" hidden></p>
	</div>

	<section class="section" aria-labelledby="zuege-h">
		<div class="section__head">
			<h2 class="section__title" id="zuege-h">Z&uuml;ge unterwegs</h2>
			<ul class="legende" aria-label="Legende">
				<li><span class="legende__punkt legende__punkt--spaet"></span>ab 3 Min. versp&auml;tet</li>
				<li><span class="legende__punkt legende__punkt--ausfall"></span>ab 10 Min.</li>
			</ul>
		</div>
		<div class="card" id="karte-liste"><?= nb_karte_liste($zuege) ?></div>
		<p class="section__note">Die Positionen sind gerechnet, nicht geortet: Aus dem Laufweg jedes Zuges (Soll-Zeit plus Prognose je Halt, Quelle Stationsmonitor der Nordbahn) ergibt sich, zwischen welchen Halten er gerade ist und wie weit. Zwischen zwei Abrufen bewegen sich die Z&uuml;ge nach diesen Zeiten weiter, eine neue Prognose kann sie springen lassen. <?= !empty($strecke['_quelle']) ? 'Der Streckenverlauf folgt den Gleisen laut OpenStreetMap.' : 'Der Streckenverlauf ist vereinfacht.' ?> Z&uuml;ge erscheinen bis 20 Minuten vor der Abfahrt am Startbahnhof. Kartendaten &copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a>-Mitwirkende.</p>
	</section>
</main>

<?php $current = 'karte'; require __DIR__ . '/../inc/footer.php'; ?>

<script id="karte-strecke" type="application/json"><?= $streckeJson ?></script>
<script id="karte-stand" type="application/json"><?= $standJson ?></script>
<script src="<?= $root ?>vendor/leaflet/leaflet.js?v=<?= $leafletVersion ?>" defer></script>
<script src="<?= $root ?>karte.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
