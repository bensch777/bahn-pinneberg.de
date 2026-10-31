<?php
/**
 * Domain-Nachweis fuer das ChatGPT-App-Verzeichnis, ausgeliefert als
 * /.well-known/openai-apps-challenge. Der Token kommt aus config.php
 * (NORDBAHN_OPENAI_CHALLENGE); ohne Token 404.
 */
require __DIR__ . '/api/store.php';

$token = trim((string) (nordbahn_config()['NORDBAHN_OPENAI_CHALLENGE'] ?? ''));
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if ($token === '') {
    http_response_code(404);
    exit;
}
echo $token;
