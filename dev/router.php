<?php
/**
 * Router fuer den eingebauten PHP-Server, bildet die Rewrites der .htaccess nach:
 *
 *   php -S 127.0.0.1:8080 dev/router.php
 *
 * Ohne config.php laeuft die Seite mit leeren Daten; api/abfahrten fragt den
 * Stationsmonitor live ab.
 *
 * Demo-Modus, ganz ohne Netz und mit erfundenen Daten (siehe dev/demo.php):
 *
 *   NORDBAHN_DEMO=stoerung php -S 127.0.0.1:8080 dev/router.php
 *
 * Der Router legt dann Cache und Konfiguration auf die Demo um und fuellt den
 * Demo-Cache vor jeder Seite und jedem API-Aufruf neu. /?demo=ruhig, leicht,
 * stoerung oder bau wechselt das Szenario.
 */
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$demo = getenv('NORDBAHN_DEMO');
if ($demo !== false && $demo !== '') {
    define('NORDBAHN_DEMO_NUR_HELFER', true);
    require __DIR__ . '/demo.php';
    define('NORDBAHN_CACHE_DIR', demo_cache_dir());
    define('NORDBAHN_CONFIG_FILE', __DIR__ . '/demo-config.php');
    if (isset($_GET['demo'])) {
        demo_szenario_setzen((string) $_GET['demo']);
    }
    $szenario = demo_szenario($demo);
    // Nur fuer PHP-Seiten und Endpunkte, nicht fuer Bilder, Styles und Scripts; in einem
    // eigenen Prozess, weil die Demo die API-Dateien laedt, die gleich als Endpunkt dran sind
    if (!preg_match('/\.(css|js|png|ico|woff2|json|webp|webmanifest|txt)$/', $path) && !demo_frisch($szenario)) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/demo.php') . ' ' . escapeshellarg($szenario) . ' 2>&1', $ausgabe, $status);
        if ($status !== 0) {
            error_log('Demo-Daten: ' . implode("\n", $ausgabe));
        }
    }
}
// Gleiche Regeln wie in der .htaccess: Seiten aus seiten/, Endpunkte aus api/,
// feste Adressen im Wurzelverzeichnis aus assets/, alles andere ausser assets/ gibt es nicht
$seiten = 'aktuell|changelog|eilmeldungen|einstellungen|faq|impressum|karte|mcp-hilfe|news|nutzungsbedingungen|statistik|stoerungen';
$dateien = [
    '/sitemap.xml' => '/seiten/sitemap.php',
    '/robots.txt'  => '/seiten/robots.php',
    '/llms.txt'    => '/seiten/llms.php',
    '/lage.md'     => '/seiten/lage.php',
    '/.well-known/openai-apps-challenge' => '/seiten/openai-challenge.php',
    '/mcp'         => '/api/mcp.php',
    '/mcp/'        => '/api/mcp.php',
];
$statisch = [
    '#^/sw\.js$#' => '/assets/js/sw.js',
    '#^/manifest\.webmanifest$#' => '/assets/manifest.webmanifest',
    '#^/favicon\.ico$#' => '/assets/icons/favicon.ico',
    '#^/(icon-[a-z0-9-]+\.png)$#' => '/assets/icons/$1',
    '#^/og/([a-z]+\.png)$#' => '/assets/og/$1',
    '#^/(google[0-9a-f]+\.html)$#' => '/assets/$1',
];
$ziel = null;
if ($path === '/') {
    $ziel = '/seiten/start.php';
} elseif ($path === '/index.php') {
    header('Location: /', true, 301);
    return true;
} elseif (preg_match('#^/(' . $seiten . ')(/index\.php)?$#', $path, $m)) {
    header('Location: /' . $m[1] . '/', true, 301);
    return true;
} elseif (preg_match('#^/(' . $seiten . ')/$#', $path, $m)) {
    $ziel = '/seiten/' . $m[1] . '.php';
} elseif (preg_match('#^/(stoerung|eilmeldung)/([A-Za-z0-9_.-]+)(?:/([a-z0-9-]*))?/?$#', $path, $m)) {
    $_GET[$m[1] === 'stoerung' ? 'key' : 'id'] = $m[2];
    $_GET['slug'] = $m[3] ?? '';
    $ziel = '/seiten/' . $m[1] . '.php';
} elseif (isset($dateien[$path])) {
    $ziel = $dateien[$path];
} elseif (preg_match('#^/api/([a-z0-9-]+)$#', $path, $m) && is_file($root . '/api/' . $m[1] . '.php')) {
    $ziel = '/api/' . $m[1] . '.php';
} elseif (preg_match('#^/pendler/verwalten/?$#', $path)) {
    $_GET['verwalten'] = '1';
    $ziel = '/api/pendler.php';
}
if ($ziel !== null) {
    chdir(dirname($root . $ziel));
    require $root . $ziel;
    return true;
}
foreach ($statisch as $muster => $datei) {
    if (preg_match($muster, $path)) {
        $datei = $root . preg_replace($muster, $datei, $path);
        $typen = ['js' => 'text/javascript', 'webmanifest' => 'application/manifest+json', 'ico' => 'image/x-icon', 'png' => 'image/png', 'html' => 'text/html'];
        header('Content-Type: ' . ($typen[pathinfo($datei, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($datei);
        return true;
    }
}
// Was es nicht gibt, bekommt wie bei Apache (ErrorDocument) die Fehlerseite
if (!preg_match('#^/(assets|api)/[^/]+#', $path) || preg_match('#/$|(^|/)\.#', $path) || !is_file($root . $path)) {
    $_SERVER['REDIRECT_STATUS'] = '404';
    $_SERVER['REDIRECT_URL'] = $path;
    require $root . '/seiten/fehler.php';
    return true;
}

return false; // statische Datei
