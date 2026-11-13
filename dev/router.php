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
$map = [
    '/api/data'      => '/api/data.php',
    '/api/push'      => '/api/push.php',
    '/api/abfahrten' => '/api/abfahrten.php',
    '/api/revision'  => '/api/revision.php',
    '/api/subscribe' => '/api/subscribe.php',
    '/api/push-key'  => '/api/push-key.php',
    '/api/s3'        => '/api/s3.php',
    '/api/regio'     => '/api/regio.php',
    '/api/karte'     => '/api/karte.php',
    '/api/feedback'  => '/api/feedback.php',
    '/api/fahrplan'  => '/api/fahrplan.php',
    '/mcp'           => '/api/mcp.php',
    '/mcp-hilfe/'    => '/mcp-hilfe/index.php',
    '/nutzungsbedingungen/' => '/nutzungsbedingungen/index.php',
    '/.well-known/openai-apps-challenge' => '/openai-challenge.php',
    '/sitemap.xml'   => '/sitemap.php',
    '/llms.txt'      => '/llms.php',
    '/lage.md'       => '/lage.php',
    '/eilmeldungen/' => '/eilmeldungen/index.php',
    '/stoerungen/'   => '/stoerungen/index.php',
    '/news/'         => '/news/index.php',
    '/aktuell/'      => '/aktuell/index.php',
    '/robots.txt'    => '/robots.php',
    '/'              => '/index.php',
    '/faq/'          => '/faq/index.php',
    '/changelog/'    => '/changelog/index.php',
    '/statistik/'    => '/statistik/index.php',
    '/karte/'        => '/karte/index.php',
    '/impressum/'    => '/impressum/index.php',
];
if (preg_match('#^/(cache|tests|dev|\.git)(/|$)#', $path) || preg_match('#^/(config(\.example)?\.php|README\.md)$#', $path)) {
    http_response_code(403);
    return true;
}
if (preg_match('#^/(stoerung|eilmeldung)/([A-Za-z0-9_.-]+)(?:/([a-z0-9-]*))?/?$#', $path, $m)) {
    $_GET[$m[1] === 'stoerung' ? 'key' : 'id'] = $m[2];
    $_GET['slug'] = $m[3] ?? '';
    chdir($root);
    require $root . '/' . $m[1] . '.php';
    return true;
}
if (isset($map[$path])) {
    chdir(dirname($root . $map[$path]));
    require $root . $map[$path];
    return true;
}

return false; // statische Datei oder 404
