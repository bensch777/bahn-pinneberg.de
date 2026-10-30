<?php
/**
 * Anleitung fuer den MCP-Server (/mcp-hilfe/): was er kann, wie man ihn in Claude
 * und ChatGPT eintraegt, Werkzeuge, Grenzen, Datenschutz und Kontakt. Dient
 * zugleich als Dokumentation fuer die Connector-Verzeichnisse von Anthropic
 * und OpenAI.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = (int) @filemtime(__DIR__ . '/../dashboard.css');
$mcpUrl = SITE_URL . '/mcp';
$bahnhoefe = implode(', ', array_column(NORDBAHN_BAHNHOEFE, 'name'));

$title = 'Bahn-Pinneberg.de in Claude und ChatGPT – MCP-Server';
$description = 'Mit dem MCP-Server von Bahn-Pinneberg.de beantworten Claude, ChatGPT und andere KI-Assistenten live, ob RB60, RB61 und RB71 gerade fahren: Störungen, Abfahrten, Ausfälle.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/mcp-hilfe/">
<meta name="robots" content="index, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Bahn-Pinneberg.de">
<meta property="og:locale" content="de_DE">
<meta property="og:url" content="<?= SITE_URL ?>/mcp-hilfe/">
<meta property="og:title" content="<?= nb_e($title) ?>">
<meta property="og:description" content="<?= nb_e($description) ?>">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'mcp-hilfe') ?>

<main class="wrap">
	<div class="page-head">
		<div>
			<h1>In Claude und ChatGPT</h1>
			<p class="page-head__lead">Frag deinen KI-Assistenten, ob deine Bahn fährt, und bekomm den Stand von jetzt.</p>
		</div>
	</div>

	<section class="section">
		<div class="card prose">
			<h2>Was ist das?</h2>
			<p>Bahn-Pinneberg.de bietet einen MCP-Server an (Model Context Protocol). Trägst du ihn in Claude, ChatGPT oder einem anderen Assistenten als Connector ein, fragt der Assistent bei Fragen zur Nordbahn direkt hier nach: aktive Störungen, die nächsten Züge ab deinem Bahnhof, Ausfälle und Verspätungen. Ohne Connector antwortet ein Assistent oft aus einem Suchindex, der Stunden alt sein kann.</p>
			<p>Adresse des Servers: <code><?= nb_e($mcpUrl) ?></code><br>Ohne Anmeldung, ohne Konto, kostenlos. Der Server kann nur lesen.</p>

			<h2>Einrichten in Claude</h2>
			<ol>
				<li>In Claude die Einstellungen öffnen und zu <strong>Connectors</strong> gehen.</li>
				<li><strong>Eigenen Connector hinzufügen</strong> wählen, als Name „Bahn-Pinneberg“, als Adresse <code><?= nb_e($mcpUrl) ?></code>.</li>
				<li>Speichern. Eine Anmeldung folgt nicht. Im Chat lässt sich der Connector über das Werkzeug-Menü ein- und ausschalten.</li>
			</ol>

			<h2>Einrichten in ChatGPT</h2>
			<ol>
				<li>In ChatGPT die Einstellungen öffnen, unter <strong>Apps &amp; Connectors</strong> bei den erweiterten Einstellungen den Entwicklermodus einschalten.</li>
				<li>Einen neuen Connector anlegen, als Adresse <code><?= nb_e($mcpUrl) ?></code>, Authentifizierung „Keine“.</li>
				<li>Im Chat den Connector auswählen und fragen.</li>
			</ol>
			<p>Die Menüs heißen je nach Version und Tarif etwas anders. Andere MCP-Clients brauchen nur die Adresse; der Server spricht „Streamable HTTP“.</p>

			<h2>Beispielfragen</h2>
			<ul>
				<li>„Gibt es gerade Probleme auf der RB61?“</li>
				<li>„Fährt der nächste Zug ab Elmshorn nach Hamburg pünktlich?“</li>
				<li>„Wie komme ich heute Abend vom Hauptbahnhof nach Tornesch, fällt etwas aus?“</li>
				<li>„Was ist mit der Störung bei Pinneberg, wie lange dauert die noch?“</li>
			</ul>

			<h2>Werkzeuge</h2>
			<ul>
				<li><strong>lage</strong>: die ganze Lage für einen Bahnhof, wie auf der Startseite. Status, aktive Störungen, die nächsten Züge in beide Richtungen, Eilmeldungen der letzten 24 Stunden, zuletzt aufgehobene Störungen.</li>
				<li><strong>abfahrten</strong>: die nächsten Züge ab deinem Bahnhof nach Hamburg oder von Hamburg zurück, mit Verspätung, Gleis, Ausfall und Teilausfall.</li>
				<li><strong>stoerung</strong>: Details zu einer Infrastrukturstörung mit Pendler-Text, Originalmeldung, Abschnitt und Zeitraum.</li>
			</ul>
			<p>Bahnhöfe: <?= nb_e($bahnhoefe) ?>. Der Assistent versteht Namen und Kürzel.</p>

			<h2>Grenzen</h2>
			<p>Die Daten sind dieselben wie auf der Seite und höchstens ein bis zwei Minuten alt. Verbindlich sind allein die Auskünfte der Nordbahn. Je Nutzer und Stunde gehen bis zu 120 Abfragen; das reicht für jede normale Unterhaltung. Was der Assistent aus den Daten macht, liegt beim Assistenten: Bei wichtigen Fahrten lieber einmal auf der <a href="<?= $root ?>">Startseite</a> nachsehen.</p>

			<h2>Datenschutz</h2>
			<p>Der Server bekommt nur die Anfrage des Assistenten, also Werkzeug, Bahnhof, Richtung oder den Key einer Störung, nicht den Inhalt deiner Unterhaltung. Gespeichert wird kein Inhalt, nur wie oft ein Werkzeug am Tag aufgerufen wurde. Details stehen in der <a href="<?= $root ?>impressum/#mcp">Datenschutzerklärung</a>, die Regeln für die Nutzung in den <a href="<?= $root ?>nutzungsbedingungen/">Nutzungsbedingungen</a>.</p>

			<h2>Hilfe und Kontakt</h2>
			<p>Funktioniert etwas nicht, hilft der Knopf „Feedback“ unten links oder eine E-Mail an <a href="mailto:hallo@bahn-pinneberg.de">hallo@bahn-pinneberg.de</a>. Hilfreich sind Assistent, Uhrzeit und die Frage, die du gestellt hast.</p>
		</div>
	</section>
</main>

<?php $current = 'mcp-hilfe'; require __DIR__ . '/../inc/footer.php'; ?>
</body>
</html>
