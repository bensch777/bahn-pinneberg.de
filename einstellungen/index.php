<?php
/**
 * Einstellungen: Meine Zuege und Benachrichtigungen, im Stil einer
 * Systemeinstellung (Karten mit Zeilen, Kippschalter, Segmente). Erreichbar
 * ueber die Glocke im Kopf jeder Seite und aus dem Handy-Menue. Alles liegt im
 * Browser, gemerkte Zuege zusaetzlich am Push-Abo; dashboard.js steuert die
 * Seite (data-seite="einstellungen"), ohne JavaScript bleibt nur der Hinweis.
 */

require __DIR__ . '/../api/store.php';
require_once __DIR__ . '/../api/render.php';
require __DIR__ . '/../api/notify.php';
require __DIR__ . '/../inc/security.php';
nb_send_security_headers();

define('SITE_URL', nordbahn_site_url());
$root = '../';
$assetVersion = max((int) @filemtime(__DIR__ . '/../dashboard.js'), (int) @filemtime(__DIR__ . '/../dashboard.css'));
$heimat = NORDBAHN_HEIMAT_DEFAULT;
$name = NORDBAHN_BAHNHOEFE[$heimat]['name'];

$title = 'Einstellungen – Bahn-Pinneberg.de';
$description = 'Meine Züge und Benachrichtigungen für Bahn-Pinneberg.de: Push-Nachricht, wenn dein Zug später kommt, ausfällt oder woanders abfährt.';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="description" content="<?= nb_e($description) ?>">
<link rel="canonical" href="<?= SITE_URL ?>/einstellungen/">
<meta name="robots" content="noindex, follow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>icon-180.png?v=2">
<link rel="manifest" href="<?= $root ?>manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Bahn Pinneberg">
<link rel="stylesheet" href="<?= $root ?>dashboard.css?v=<?= $assetVersion ?>">
<?php require __DIR__ . '/../inc/analytics.php'; ?>
</head>
<body data-seite="einstellungen" data-root="<?= $root ?>" data-push-key="<?= nb_e(nb_push_public_key()) ?>">

<?= nb_render_kopf($root, 'einstellungen') ?>

<main class="wrap einstellungen">
	<div class="page-head">
		<div>
			<h1>Einstellungen</h1>
			<p class="page-head__lead">Gilt f&uuml;r diesen Browser, ohne Konto.</p>
		</div>
	</div>

	<noscript><p class="notice">Die Einstellungen brauchen JavaScript.</p></noscript>

	<section id="meine" class="section" aria-labelledby="meine-h">
		<div class="card einst">
			<h2 id="meine-h" class="einst__label">Meine Z&uuml;ge</h2>
			<p class="einst__intro">Push-Nachricht ab einer Stunde vor der Abfahrt, wenn dein Zug sp&auml;ter kommt, ausf&auml;llt, woanders abf&auml;hrt oder das Gleis wechselt.</p>
			<ul class="meine__liste" id="meine-zuege-liste"></ul>
			<p class="meine__leer-hinweis" id="meine-leer">Noch kein Zug gemerkt. Such unten deine Abfahrt, zum Beispiel 8:13.</p>
			<div class="meine__neu">
				<div class="einst__zeile">
					<label class="einst__text" for="heimat">Bahnhof</label>
					<select id="heimat" class="heimat__select" data-heimat-default="<?= $heimat ?>">
<?php foreach (NORDBAHN_BAHNHOEFE as $id => $bhf): ?>
						<option value="<?= $id ?>" data-ast="<?= $bhf['ast'] ?>"<?= $id === $heimat ? ' selected' : '' ?>><?= nb_e($bhf['name']) ?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div class="segment" role="radiogroup" aria-label="Richtung">
					<label class="segment__opt"><input type="radio" name="meine-richtung" value="hin" checked><span id="meine-label-hin"><?= nb_e($name) ?> &rarr; Hamburg</span></label>
					<label class="segment__opt"><input type="radio" name="meine-richtung" value="rueck"><span id="meine-label-rueck">Hamburg &rarr; <?= nb_e($name) ?></span></label>
				</div>
				<div class="meine__suche">
					<label class="visually-hidden" for="meine-suche">Zug suchen</label>
					<input id="meine-suche" class="meine__eingabe" type="search" placeholder="Abfahrt suchen, z. B. 8:13" autocomplete="off" enterkeyhint="done" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="meine-vorschlaege">
					<ul class="meine__vorschlaege" id="meine-vorschlaege" role="listbox" aria-label="Passende Z&uuml;ge" hidden></ul>
				</div>
			</div>
			<p class="meine__hinweis" id="meine-hinweis" hidden></p>
		</div>
	</section>

	<section id="benachrichtigungen" class="section" aria-labelledby="benachrichtigungen-h">
		<div class="card einst">
			<h2 id="benachrichtigungen-h" class="einst__label">Benachrichtigungen</h2>
			<label class="einst__zeile einst__zeile--schalter" for="notify-on">
				<span class="einst__text">Auf diesem Ger&auml;t<small class="einst__hinweis" id="notify-hint" hidden></small><small class="einst__hinweis" id="notify-push-hint" hidden></small></span>
				<span class="schalter"><input type="checkbox" id="notify-on" role="switch"><span class="schalter__knopf" aria-hidden="true"></span></span>
			</label>
			<div class="einst__zeile einst__zeile--stapel" id="notify-was">
				<span class="einst__text" id="notify-was-label">Was</span>
				<div class="segment" role="radiogroup" aria-labelledby="notify-was-label">
					<label class="segment__opt"><input type="radio" name="notify-was" value="alles"><span>Alles</span></label>
					<label class="segment__opt"><input type="radio" name="notify-was" value="nur"><span>Nur meine Z&uuml;ge</span></label>
				</div>
				<p class="einst__erklaerung" id="notify-was-text"></p>
			</div>
			<div class="einst__zeile einst__zeile--stapel" id="notify-wann">
				<span class="einst__text" id="notify-wann-label">Wann</span>
				<div class="segment" role="radiogroup" aria-labelledby="notify-wann-label">
					<label class="segment__opt"><input type="radio" name="notify-zeit" value="pendel"><span>Pendelzeiten</span></label>
					<label class="segment__opt"><input type="radio" name="notify-zeit" value="immer"><span>Immer</span></label>
				</div>
				<p class="einst__erklaerung" id="notify-wann-text"></p>
			</div>
			<details class="installieren" id="installieren">
				<summary>So kommt die Seite auf den Home-Bildschirm</summary>
				<div class="installieren__wege">
					<div>
						<h3>iPhone und iPad (Safari)</h3>
						<ol>
							<li>Unten auf <span class="installieren__icon" aria-label="Teilen"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5 16.5 7l-1.4 1.4L13 6.3V15h-2V6.3L8.9 8.4 7.5 7 12 2.5ZM5 10h3v2H6v8h12v-8h-2v-2h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1Z"/></svg></span> <strong>Teilen</strong> tippen.</li>
							<li>Etwas nach unten scrollen und <span class="installieren__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h14V5H5Zm6 3h2v3h3v2h-3v3h-2v-3H8v-2h3V8Z"/></svg></span> <strong>Zum Home-Bildschirm</strong> w&auml;hlen.</li>
							<li>Oben rechts <strong>Hinzuf&uuml;gen</strong> tippen.</li>
							<li>Die Seite ab jetzt &uuml;ber das Symbol <strong>Bahn Pinneberg</strong> &ouml;ffnen und hier die Benachrichtigungen einschalten. Im Safari-Tab geht das auf dem iPhone nicht.</li>
						</ol>
					</div>
					<div>
						<h3>Android (Chrome)</h3>
						<ol>
							<li>Oben rechts auf das Men&uuml; <strong>&#8942;</strong> tippen.</li>
							<li><strong>App installieren</strong> oder <strong>Zum Startbildschirm hinzuf&uuml;gen</strong> w&auml;hlen.</li>
							<li>Benachrichtigungen gehen auch ohne Installation, die App startet aber schneller und im Vollbild.</li>
						</ol>
					</div>
				</div>
			</details>
		</div>
	</section>

	<p class="einst__fuss">Alles liegt nur in diesem Browser und f&uuml;r Push-Nachrichten am Abo. <button type="button" class="einst__loeschen" id="daten-loeschen">Alles l&ouml;schen</button></p>
</main>

<?php $current = 'einstellungen'; require __DIR__ . '/../inc/footer.php'; ?>
<script src="<?= $root ?>dashboard.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
