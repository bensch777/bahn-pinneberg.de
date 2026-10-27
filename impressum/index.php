<?php
/**
 * Impressum und Datenschutzerklaerung. Die Anbieterangaben stehen hier als
 * Vorgabe (oeffentliche Impressumsadresse) und lassen sich per config.php
 * (NORDBAHN_IMPRESSUM) ueberschreiben, z. B. fuer den Hoster-Namen.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$config = nordbahn_config();
$imp = is_array($config['NORDBAHN_IMPRESSUM'] ?? null) ? $config['NORDBAHN_IMPRESSUM'] : [];
$imp += [
    'name'    => 'Benjamin Neske',
    'zusatz'  => 'c/o Rechtsanwalt Matutis',
    'strasse' => 'Berliner Straße 57',
    'ort'     => '14467 Potsdam',
    'land'    => 'Deutschland',
    'email'   => 'hallo@bahn-pinneberg.de',
    'hoster'  => '',
];
$vollstaendig = $imp['name'] !== '' && $imp['strasse'] !== '' && $imp['ort'] !== '' && $imp['email'] !== '';
$anschrift = array_values(array_filter([$imp['name'], $imp['zusatz'], $imp['strasse'], $imp['ort'], $imp['land']], 'strlen'));

$title = 'Impressum und Datenschutz – Bahn-Pinneberg.de';
$description = 'Anbieterkennzeichnung und Datenschutzerklärung von Bahn-Pinneberg.de.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/impressum/">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Bahn-Pinneberg.de">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/impressum/">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'impressum') ?>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>Impressum</h1>
			<p class="page-head__lead">Wer hinter Bahn-Pinneberg.de steht und was mit Ihren Daten passiert.</p>
		</div>
	</div>

	<section class="section">
		<div class="card prose">
			<h2>Angaben gem&auml;&szlig; &sect; 5 DDG</h2>
<?php if ($vollstaendig): ?>
			<p><?= implode('<br>', array_map('nb_e', $anschrift)) ?></p>
			<h2>Kontakt</h2>
			<p>E-Mail: <a href="mailto:<?= nb_e($imp['email']) ?>"><?= nb_e($imp['email']) ?></a></p>
			<h2>Verantwortlich f&uuml;r den Inhalt</h2>
			<p><?= nb_e($imp['name']) ?>, Anschrift wie oben.</p>
<?php else: ?>
			<p><strong>Die Anbieterangaben sind noch nicht hinterlegt.</strong> Sie werden in der Konfiguration des Servers gepflegt (NORDBAHN_IMPRESSUM in config.php).</p>
<?php endif; ?>
			<h2>Hinweis zum Projekt</h2>
			<p>Bahn-Pinneberg.de ist ein privates, nicht kommerzielles Projekt ohne Verbindung zur NBE Nordbahn Eisenbahngesellschaft mbH, zur DB InfraGO AG oder zur NAH.SH GmbH. Alle Angaben zu St&ouml;rungen, Abfahrten und Eilmeldungen stammen aus &ouml;ffentlichen Quellen (nordbahn.de, strecken-info.de, HVV-Schnittstelle) und werden automatisch aufbereitet. F&uuml;r Richtigkeit, Vollst&auml;ndigkeit und Aktualit&auml;t wird keine Gew&auml;hr &uuml;bernommen. Verbindlich sind allein die Ausk&uuml;nfte der Nordbahn.</p>
			<h2>Haftung f&uuml;r Links</h2>
			<p>Die Seite verlinkt auf Angebote Dritter (nordbahn.de, strecken-info.de, Telegram, X). Auf deren Inhalte habe ich keinen Einfluss; es gelten die Angaben der jeweiligen Anbieter.</p>
		</div>
	</section>

	<section class="section" id="datenschutz">
		<div class="section__head">
			<h2 class="section__title">Datenschutzerkl&auml;rung</h2>
		</div>
		<div class="card prose">
			<h2>Verantwortlicher</h2>
			<p><?= $vollstaendig ? nb_e(implode(', ', $anschrift)) . ', E-Mail <a href="mailto:' . nb_e($imp['email']) . '">' . nb_e($imp['email']) . '</a>' : 'Siehe Impressum.' ?></p>

			<h2>Was diese Seite nicht tut</h2>
			<p>Es gibt kein Konto, kein Formular, keinen Newsletter und keine Cookies. Es werden keine personenbezogenen Daten an Dritte weitergegeben oder zu Werbezwecken verarbeitet.</p>

			<h2>Server-Logs</h2>
			<p>Beim Aufruf speichert der Webserver<?= $imp['hoster'] !== '' ? ' (Hosting: ' . nb_e($imp['hoster']) . ')' : '' ?> technisch bedingt IP-Adresse, Zeitpunkt, aufgerufene Adresse, &uuml;bertragene Datenmenge, Browser und Referrer in Logdateien. Das dient dem sicheren Betrieb und der Fehlersuche (Art. 6 Abs. 1 lit. f DSGVO). Die Logs werden nach den Fristen des Hosting-Anbieters gel&ouml;scht.</p>

			<h2>Reichweitenmessung mit Plausible</h2>
			<p>Zur Messung der Nutzung wird Plausible Analytics eingesetzt (Plausible Insights O&Uuml;, Estland, Server in der EU). Plausible arbeitet ohne Cookies und ohne Speicherung personenbezogener Daten: Die IP-Adresse wird nur fl&uuml;chtig zur Bildung eines t&auml;glich wechselnden, nicht r&uuml;ckrechenbaren Kennzeichens verwendet und nicht gespeichert. Erfasst werden Seitenaufrufe, Referrer, Ger&auml;tetyp, Browser und Land, dazu der im Browser gew&auml;hlte Heimatbahnhof als Eigenschaft des Seitenaufrufs sowie einzelne Aktionen (z. B. Wechsel des Heimatbahnhofs, Klick auf einen Telegram-Kanal). Rechtsgrundlage ist das berechtigte Interesse, die Nutzung der Seite zu verstehen (Art. 6 Abs. 1 lit. f DSGVO). Details: <a href="https://plausible.io/data-policy" rel="noopener">plausible.io/data-policy</a>.</p>

			<h2>Einstellungen im Browser</h2>
			<p>Heimatbahnhof und Benachrichtigungseinstellungen werden ausschlie&szlig;lich im lokalen Speicher Ihres Browsers (localStorage) abgelegt. Diese Daten verlassen Ihr Ger&auml;t nicht und lassen sich &uuml;ber die Browser-Einstellungen l&ouml;schen.</p>

			<h2>Benachrichtigungen (Web Push)</h2>
			<p>Wenn Sie Benachrichtigungen einschalten, richtet Ihr Browser ein Push-Abo beim Push-Dienst seines Herstellers ein (z. B. Google f&uuml;r Chrome, Apple f&uuml;r Safari, Mozilla f&uuml;r Firefox). Auf dem Server dieser Seite werden dazu die Abo-Adresse, die vom Browser erzeugten Verschl&uuml;sselungsschl&uuml;ssel, Ihr gew&auml;hlter Heimatbahnhof und die Zeitregel gespeichert, sonst nichts; eine Zuordnung zu Ihrer Person ist damit nicht m&ouml;glich. Nachrichten werden Ende-zu-Ende verschl&uuml;sselt an den Push-Dienst &uuml;bergeben, der nur die Abo-Adresse kennt. Rechtsgrundlage ist Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die Sie jederzeit &uuml;ber die Glocke oder die Browser-Einstellungen widerrufen; das Abo wird dann gel&ouml;scht, nicht mehr erreichbare Abos l&ouml;schen wir automatisch.</p>

			<h2>Feedback-Formular</h2>
			<p>&Uuml;ber den Knopf &bdquo;Feedback&ldquo; k&ouml;nnen Sie uns eine Nachricht schicken. &Uuml;bermittelt werden Ihr Text, die gew&auml;hlte Art, ein freiwilliger Kontakt, der Pfad der Seite, von der Sie schreiben, und die Browser-Kennung (User-Agent), damit sich Anzeigefehler nachvollziehen lassen. Die Nachricht wird nicht auf unserem Server gespeichert, sondern an einen privaten Kanal bei Discord (Discord Inc., USA) weitergeleitet, den nur der Betreiber liest; f&uuml;r die &Uuml;bermittlung in die USA gelten die Standardvertragsklauseln von Discord. Zum Schutz vor Missbrauch bildet der Server aus IP-Adresse, Browser-Kennung und Datum ein t&auml;glich wechselndes, nicht r&uuml;ckrechenbares Kennzeichen und merkt sich dazu f&uuml;r eine Stunde die Zeitpunkte Ihrer Nachrichten. Rechtsgrundlage ist Ihre Einwilligung durch das Absenden (Art. 6 Abs. 1 lit. a DSGVO). Bitte geben Sie keine sensiblen Daten an.</p>

			<h2>Datenquellen und externe Dienste</h2>
			<p>Die Seite l&auml;dt St&ouml;rungen, Abfahrten und Eilmeldungen serverseitig von nordbahn.de und strecken-info.de; Ihr Browser verbindet sich dabei nicht mit diesen Diensten. Die S-Bahn-Abfahrten und HVV-Meldungen fragt der Server &uuml;ber die Schnittstelle des HVV (Geofox GTI, betrieben von der HBT Hamburger Berater Team GmbH) ab; &uuml;bertragen werden dabei nur Haltestelle, Linie und Zeitfenster, keine Daten von Besuchern. Links zu Telegram, X, nordbahn.de und strecken-info.de f&uuml;hren zu Angeboten Dritter mit eigenen Datenschutzbestimmungen.</p>

			<h2>Kartenkacheln von OpenStreetMap (nur Live-Karte)</h2>
			<p>Die Seite <a href="<?= $root ?>karte/">Live-Karte</a> zeigt die Zugpositionen auf einer Karte. Die Kartenbilder (Kacheln) l&auml;dt Ihr Browser dabei direkt von den Servern der OpenStreetMap Foundation (St John&rsquo;s Innovation Centre, Cowley Road, Cambridge, CB4 0WS, Vereinigtes K&ouml;nigreich); dabei werden IP-Adresse, Browser, Referrer und der gezeigte Kartenausschnitt an diese &uuml;bertragen. Die Kartensoftware (Leaflet) und die Zugpositionen kommen vom Server dieser Seite. Rechtsgrundlage ist das berechtigte Interesse, die Lage der Z&uuml;ge auf einer Karte darzustellen (Art. 6 Abs. 1 lit. f DSGVO); auf allen anderen Seiten findet keine Verbindung zu OpenStreetMap statt. Details: <a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener">osmfoundation.org/wiki/Privacy_Policy</a>.</p>

			<h2>Ihre Rechte</h2>
			<p>Sie haben das Recht auf Auskunft, Berichtigung, L&ouml;schung, Einschr&auml;nkung der Verarbeitung, Daten&uuml;bertragbarkeit und Widerspruch (Art. 15 bis 21 DSGVO) sowie auf Beschwerde bei einer Datenschutz-Aufsichtsbeh&ouml;rde. Da diese Seite keine personenbezogenen Daten speichert, beschr&auml;nkt sich das in der Praxis auf die Server-Logs des Hosting-Anbieters.</p>

			<p class="section__note">Stand: <?= nb_e(date('d.m.Y', (int) @filemtime(__FILE__))) ?></p>
		</div>
	</section>
</main>

<?php $current = 'impressum'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
