<?php
/**
 * Nutzungsbedingungen (/nutzungsbedingungen/) fuer Seite, Datenschnittstellen und
 * MCP-Server. Kurz gehalten; die Connector-Verzeichnisse von Anthropic und
 * OpenAI verlangen eine stabile Adresse dafuer.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');

$title = 'Nutzungsbedingungen – Bahn-Pinneberg.de';
$description = 'Nutzungsbedingungen von Bahn-Pinneberg.de für Website, Datenschnittstellen und MCP-Server.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/nutzungsbedingungen/">
<meta name="robots" content="noindex, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'nutzungsbedingungen') ?>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>Nutzungsbedingungen</h1>
			<p class="page-head__lead">Was gilt, wenn Sie Bahn-Pinneberg.de, die Datenschnittstellen oder den MCP-Server nutzen.</p>
		</div>
	</div>

	<section class="section">
		<div class="card prose">
			<h2>1. Anbieter und Geltung</h2>
			<p>Bahn-Pinneberg.de ist ein privates, nicht kommerzielles Projekt; Anbieter siehe <a href="<?= $root ?>impressum/">Impressum</a>. Diese Bedingungen gelten für die Website, die öffentlichen Datenschnittstellen (lage.md, llms.txt, /api/) und den MCP-Server unter <code><?= nb_e(SITE_URL) ?>/mcp</code>, auch wenn ein KI-Assistent sie im Auftrag eines Nutzers aufruft.</p>

			<h2>2. Leistung</h2>
			<p>Die Seite bereitet öffentliche Angaben zu Störungen, Abfahrten und Eilmeldungen der Nordbahn-Linien RB60, RB61 und RB71 automatisch auf. Die Nutzung ist kostenlos und ohne Konto möglich. Es besteht kein Anspruch auf eine bestimmte Verfügbarkeit, auf bestimmte Funktionen oder auf den Fortbestand des Angebots; Funktionen können sich jederzeit ändern oder wegfallen.</p>

			<h2>3. Keine verbindliche Auskunft</h2>
			<p>Die Angaben stammen aus Quellen Dritter (Nordbahn, DB InfraGO, HVV) und können unvollständig, verspätet oder falsch sein. Texte in Alltagssprache sind teilweise von einem Sprachmodell formuliert. Verbindlich sind allein die Auskünfte der Nordbahn. Das Angebot ist keine offizielle Seite der NBE Nordbahn Eisenbahngesellschaft und steht mit ihr in keiner Verbindung.</p>

			<h2>4. Faire Nutzung</h2>
			<p>Die Datenschnittstellen bitte höchstens einmal pro Minute abrufen. Der MCP-Server erlaubt bis zu 120 Werkzeugaufrufe je Nutzer und Stunde. Nicht erlaubt sind Abrufe, die den Betrieb stören oder die Quellen übermäßig belasten, Versuche, Schutzmaßnahmen zu umgehen, und das Weiterverbreiten der Daten in einer Weise, die den Eindruck einer offiziellen Auskunft der Nordbahn erweckt. Bei Missbrauch kann der Zugang ohne Ankündigung gesperrt werden.</p>

			<h2>5. Haftung</h2>
			<p>Für Schäden, die aus der Nutzung der Angaben entstehen, etwa verpasste Züge oder Termine, wird nicht gehaftet, außer bei Vorsatz und grober Fahrlässigkeit sowie bei Verletzung von Leben, Körper oder Gesundheit.</p>

			<h2>6. KI-Assistenten</h2>
			<p>Wer den MCP-Server über einen Assistenten wie Claude oder ChatGPT nutzt, nutzt zugleich dessen Dienst; dafür gelten die Bedingungen und Datenschutzregeln des jeweiligen Anbieters. Wie der Assistent die Daten wiedergibt, liegt nicht in der Hand dieser Seite.</p>

			<h2>7. Datenschutz</h2>
			<p>Welche Daten anfallen, steht in der <a href="<?= $root ?>impressum/#datenschutz">Datenschutzerklärung</a>.</p>

			<h2>8. Schlussbestimmungen</h2>
			<p>Es gilt deutsches Recht. Diese Bedingungen können angepasst werden; es gilt die hier veröffentlichte Fassung. Fragen an <a href="mailto:hallo@bahn-pinneberg.de">hallo@bahn-pinneberg.de</a>.</p>

			<p class="section__note">Stand: <?= nb_e(date('d.m.Y', (int) @filemtime(__FILE__))) ?></p>
		</div>
	</section>
</main>

<?php $current = 'nutzungsbedingungen'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
