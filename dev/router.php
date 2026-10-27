<?php
/**
 * Router fuer den eingebauten PHP-Server, bildet die Rewrites der .htaccess nach:
 *
 *   php -S 127.0.0.1:8080 dev/router.php
 *
 * Ohne config.php laeuft die Seite mit leeren Daten; api/abfahrten fragt den
 * Stationsmonitor live ab.
 */
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$map = [
    '/api/data'      => '/api/data.php',
    '/api/push'      => '/api/push.php',
    '/api/abfahrten' => '/api/abfahrten.php',
    '/api/revision'  => '/api/revision.php',
    '/api/subscribe' => '/api/subscribe.php',
    '/api/push-key'  => '/api/push-key.php',
    '/api/s3'        => '/api/s3.php',
    '/api/karte'     => '/api/karte.php',
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
