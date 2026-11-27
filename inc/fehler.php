<?php
/**
 * Fehlerseiten. Apache leitet Fehler, die er selbst erzeugt (404 der Positivliste
 * in der .htaccess, 403, 500, 503 ...), per ErrorDocument an seiten/fehler.php;
 * der eingebaute PHP-Server bekommt sie von dev/router.php. Bricht eine Seite mit
 * einem schweren PHP-Fehler ab, zeigt nb_fehler_bei_absturz() dieselbe Seite mit 500.
 *
 * Browser bekommen eine Seite im Design mit Kopfleiste und Footer, Endpunkte unter
 * /api/ und /mcp eine maschinenlesbare Antwort (JSON bzw. JSON-RPC). Der Statuscode
 * bleibt immer der des eigentlichen Fehlers.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/security.php';

/** Code => [Kurzname fuer JSON, Ueberschrift, Text]. Unbekannte Codes gelten als 404. */
const NB_FEHLER = [
    400 => ['Ungültige Anfrage', 'Das hat der Server nicht verstanden', 'Die Adresse oder die Angaben darin passen nicht. Am besten von der Startseite aus neu ansetzen.'],
    401 => ['Nicht angemeldet', 'Kein Zugang', 'Diese Adresse ist nicht öffentlich.'],
    403 => ['Kein Zugriff', 'Kein Zugang', 'Diese Adresse ist nicht öffentlich.'],
    404 => ['Nicht gefunden', 'Diese Seite gibt es nicht', 'Vielleicht ist ein Tippfehler in der Adresse oder der Link ist veraltet. Wie die Züge gerade fahren, steht auf der Startseite.'],
    405 => ['Methode nicht erlaubt', 'So geht es leider nicht', 'Diese Adresse lässt sich auf diesem Weg nicht aufrufen. Am besten von der Startseite aus neu ansetzen.'],
    410 => ['Entfernt', 'Diese Seite gibt es nicht mehr', 'Der Inhalt wurde entfernt. Wie die Züge gerade fahren, steht auf der Startseite.'],
    429 => ['Zu viele Anfragen', 'Zu viele Anfragen', 'Bitte einen Moment warten und die Seite dann neu laden.'],
    500 => ['Serverfehler', 'Da ist etwas schiefgelaufen', 'Auf dem Server ist ein Fehler aufgetreten. Bitte in ein paar Minuten noch einmal versuchen.'],
    502 => ['Nicht erreichbar', 'Gerade nicht erreichbar', 'Der Server antwortet gerade nicht richtig. Bitte in ein paar Minuten noch einmal versuchen.'],
    503 => ['Nicht erreichbar', 'Gerade nicht erreichbar', 'Der Server ist kurz überlastet oder wird gewartet. Bitte in ein paar Minuten noch einmal versuchen.'],
    504 => ['Zeitüberschreitung', 'Gerade nicht erreichbar', 'Der Server hat zu lange gebraucht. Bitte in ein paar Minuten noch einmal versuchen.'],
];

/** Statuscode des Fehlers: von Apache (REDIRECT_STATUS) oder vorgegeben. */
function nb_fehler_code($code = null)
{
    $code = (int) ($code ?? ($_SERVER['REDIRECT_STATUS'] ?? 404));

    return isset(NB_FEHLER[$code]) ? $code : 404;
}

/** Adresse, die der Browser angefragt hat (bei ErrorDocument in REDIRECT_URL). */
function nb_fehler_pfad()
{
    $pfad = $_SERVER['REDIRECT_URL'] ?? parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

    return is_string($pfad) && $pfad !== '' ? $pfad : '/';
}

/** 'mcp', 'api' oder '' (Seite fuer Menschen). */
function nb_fehler_art($pfad)
{
    $basis = rtrim((string) parse_url(nordbahn_site_url(), PHP_URL_PATH), '/');
    if ($basis !== '' && strpos($pfad, $basis . '/') === 0) {
        $pfad = substr($pfad, strlen($basis));
    }
    if (preg_match('#^/mcp(/|$)#', $pfad)) {
        return 'mcp';
    }
    if (strpos($pfad, '/api/') === 0) {
        return 'api';
    }

    return '';
}

/** Schickt die Fehlerantwort komplett. */
function nb_fehler_antwort($code = null, $pfad = null)
{
    $code = nb_fehler_code($code);
    $pfad = $pfad ?? nb_fehler_pfad();
    $art = nb_fehler_art($pfad);
    [$kurz, $titel, $text] = NB_FEHLER[$code];
    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        if ($code === 503 && !array_filter(headers_list(), function ($h) { return stripos($h, 'Retry-After:') === 0; })) {
            header('Retry-After: 120');
        }
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($art === 'mcp') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => $kurz . ' (HTTP ' . $code . ')']], $flags);

        return;
    }
    if ($art === 'api') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'status' => $code, 'error' => $kurz], $flags);

        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        nb_send_security_headers();
    }
    // Absoluter Wurzelpfad: die Fehlerseite steht unter jeder beliebigen Adresse
    $root = rtrim((string) parse_url(nordbahn_site_url(), PHP_URL_PATH), '/') . '/';
    $assetVersion = (int) @filemtime(__DIR__ . '/../assets/css/dashboard.css');
    $nochmal = $code >= 500 || $code === 429;
    $current = 'fehler';
    $title = $titel . ' – Bahn-Pinneberg.de';
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= nb_e($title) ?></title>
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f6f4" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
<?= nb_theme_head() ?>
<link rel="icon" type="image/png" sizes="64x64" href="<?= $root ?>assets/icons/icon-64.png">
<link rel="apple-touch-icon" href="<?= $root ?>assets/icons/icon-180.png?v=2">
<link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css?v=<?= $assetVersion ?>">
<?php require __DIR__ . '/analytics.php'; ?>
</head>
<body>

<?= nb_render_kopf($root, 'fehler') ?>

<main class="wrap">
	<article class="artikel fehler">
		<header class="artikel__kopf">
			<p class="artikel__dachzeile"><span class="artikel__rubrik">Fehler <?= $code ?></span></p>
			<h1 class="artikel__titel"><?= nb_e($titel) ?></h1>
			<p class="artikel__vorspann"><?= nb_e($text) ?></p>
		</header>
		<p class="fehler__aktionen">
			<a class="fehler__knopf" href="<?= $root ?>">Zur Startseite</a>
<?php if ($nochmal): ?>
			<a class="fehler__knopf fehler__knopf--zweit" href="<?= nb_e($pfad) ?>">Neu laden</a>
<?php endif; ?>
		</p>
		<nav class="fehler__weiter" aria-label="Weitere Seiten">
			<a href="<?= $root ?>news/">Meldungen</a>
			<a href="<?= $root ?>karte/">Live-Karte</a>
			<a href="<?= $root ?>statistik/">Statistik</a>
			<a href="<?= $root ?>faq/">H&auml;ufige Fragen</a>
		</nav>
	</article>
</main>

<?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
<?php
}

/**
 * Fuer HTML-Seiten: bricht die Seite mit einem schweren PHP-Fehler ab, verwirft
 * die halbe Ausgabe und zeigt stattdessen die Fehlerseite mit 500. Dafuer puffert
 * die Seite ihre Ausgabe (nb_send_security_headers ruft das auf).
 */
function nb_fehler_bei_absturz()
{
    static $an = false;
    if ($an || PHP_SAPI === 'cli') {
        return;
    }
    $an = true;
    ob_start();
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (headers_sent()) {
            return;
        }
        header_remove('Content-Security-Policy');
        nb_fehler_antwort(500, parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    });
}
