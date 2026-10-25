<?php
/**
 * Live-Karte: wo die Zuege der RB60, RB61 und RB71 gerade sind. Die Positionen
 * rechnet api/karte.php aus dem Stationsmonitor der Nordbahn (Laufweg mit
 * Prognose je Halt), es gibt keine GPS-Ortung. Die Seite rendert die Liste aus
 * dem letzten Stand im Cache (ohne selbst abzufragen), karte.js zeichnet die
 * Karte mit Leaflet (vendor/leaflet, selbst gehostet) und OpenStreetMap-Kacheln,
 * holt alle 30 Sekunden einen frischen Stand von api/karte und bewegt die Zuege
 * dazwischen nach ihren Ist-Zeiten weiter. Der Kartenausschnitt richtet sich nach dem
 * Heimatbahnhof der Startseite; zoomen und schieben geht wie gewohnt.
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
// Kartenausschnitt nach dem Heimatbahnhof der Startseite (localStorage, siehe karte.js): Stamm -> Hamburg - Elmshorn, Aeste -> ihr Abschnitt
$heimatAnsicht = [];
foreach (NORDBAHN_BAHNHOEFE as $id => $bhf) {
    $heimatAnsicht[$id] = $bhf['ast'] === 'stamm' ? 'hamburg' : $bhf['ast'];
}
// Fester Text unter der Liste mit allen Halten, auch wenn gerade kein Zug faehrt
$halteNamen = ['Hamburg Hbf', 'Hamburg Dammtor', 'Hamburg-Altona'];
foreach (NORDBAHN_BAHNHOEFE as $bhf) {
    $halteNamen[] = $bhf['name'];
}
// Nur lesen: die Seite fragt den Monitor nicht selbst, das macht api/karte auf Anfrage des Scripts
$stand = nordbahn_read_json(KA_CACHE);
$zuege = is_array($stand) && is_array($stand['zuege'] ?? null) ? $stand['zuege'] : [];
$standJson = json_encode($stand ?? ka_leer(), $jsonFlags) ?: 'null';
$streckeJson = json_encode($strecke, $jsonFlags) ?: '{}';

$title = 'Nordbahn live: Wo ist mein Zug? RB60, RB61 und RB71 jetzt auf der Karte';
$description = 'RB60, RB61 und RB71 live auf der Karte: wo jeder Zug der Nordbahn gerade ist, mit Verspätung und nächstem Halt zwischen Hamburg, Pinneberg, Elmshorn, Itzehoe und Wrist. Jede Minute aktuell.';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Nordbahn live: Wo ist mein Zug?',
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
<meta property="og:image:alt" content="Nordbahn live: Wo ist mein Zug? RB60, RB61 und RB71 auf der Karte">
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

<?= nb_render_kopf($root, 'karte') ?>

<main class="wrap" id="karte-app" data-endpoint="<?= $root ?>api/karte" data-heimat-ansicht="<?= nb_e(json_encode($heimatAnsicht, JSON_UNESCAPED_UNICODE)) ?>" data-zug-bild="<?= $root ?>karte/zug.png?v=<?= (int) @filemtime(__DIR__ . '/zug.png') ?>">
	<div class="page-head">
		<div>
			<h1>Nordbahn live</h1>
			<p class="page-head__lead">Wo die Z&uuml;ge der RB60, RB61 und RB71 gerade sind.</p>
		</div>
		<p class="freshness<?= $zuege ? '' : ' is-empty' ?>" id="karte-freshness" aria-live="polite"><span class="dot"></span><span id="karte-freshness-text"><?= is_array($stand) && !empty($stand['fetched_at']) ? 'Letzter Abruf ' . nb_e(nb_fmt_uhr(nb_ts($stand['fetched_at']))) : 'Noch kein Stand' ?></span></p>
	</div>

	<p class="karte-status" id="karte-status"><strong id="karte-anzahl"><?= count($zuege) ?></strong> <span id="karte-anzahl-text"><?= count($zuege) === 1 ? 'Zug' : 'Züge' ?> dargestellt</span></p>

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
		<p class="section__note">Positionen berechnet aus Fahrplan und Prognose der Nordbahn, nicht geortet; Z&uuml;ge erscheinen bis 20 Minuten vor der Abfahrt. Kartendaten &copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a>-Mitwirkende.</p>
	</section>

	<section class="section" aria-labelledby="ueber-h">
		<h2 class="section__title" id="ueber-h">Welche Z&uuml;ge die Karte zeigt</h2>
		<p class="section__note section__note--text">Alle Z&uuml;ge der Nordbahn auf den Linien RB61 Hamburg Hbf &ndash; Itzehoe, RB71 Hamburg Hbf &ndash; Wrist und RB60 Hamburg-Altona &ndash; Itzehoe, mit den Halten <?= nb_e(implode(', ', $halteNamen)) ?>. Je Zug stehen Lage, Versp&auml;tung, n&auml;chster Halt und Gleis dabei, die Karte aktualisiert sich jede Minute. St&ouml;rungen, Eilmeldungen und die n&auml;chsten Abfahrten am eigenen Bahnhof zeigt die <a href="<?= $root ?>">Startseite</a>, die P&uuml;nktlichkeit je Zug die <a href="<?= $root ?>statistik/">Statistik</a>.</p>
	</section>
</main>

<?php $current = 'karte'; require __DIR__ . '/../inc/footer.php'; ?>

<script id="karte-strecke" type="application/json"><?= $streckeJson ?></script>
<script id="karte-stand" type="application/json"><?= $standJson ?></script>
<script src="<?= $root ?>vendor/leaflet/leaflet.js?v=<?= $leafletVersion ?>" defer></script>
<script src="<?= $root ?>karte.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
