<?php
/**
 * Nimmt Push-Abos aus dem Browser entgegen.
 *
 * URL: /api/subscribe (POST, JSON)
 *   { "action": "subscribe", "subscription": PushSubscription.toJSON(),
 *     "heimat": "APD", "zeit": "pendel"|"immer", "alt": alter Endpunkt (optional) }
 *   { "action": "unsubscribe", "endpoint": "..." }
 *
 * Nur von der eigenen Seite (Origin-Pruefung). Gespeichert werden Endpunkt
 * und Schluessel des Abos, Heimatbahnhof und Zeitregel.
 */

require __DIR__ . '/store.php';
require __DIR__ . '/notify.php';

function sub_respond($status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    sub_respond(405, ['ok' => false, 'error' => 'Nur POST.']);
}
if (nb_push_vapid() === null) {
    sub_respond(503, ['ok' => false, 'error' => 'Web Push ist nicht eingerichtet.']);
}
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && strcasecmp(rtrim($origin, '/'), nordbahn_site_url()) !== 0 && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? null)) {
    sub_respond(403, ['ok' => false, 'error' => 'Falscher Origin.']);
}
$raw = file_get_contents('php://input', false, null, 0, 8193);
if ($raw === false || strlen($raw) > 8192) {
    sub_respond(413, ['ok' => false, 'error' => 'Zu gross.']);
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    sub_respond(400, ['ok' => false, 'error' => 'Kein JSON.']);
}
$action = (string) ($in['action'] ?? 'subscribe');
if ($action === 'unsubscribe') {
    $endpoint = (string) ($in['endpoint'] ?? ($in['subscription']['endpoint'] ?? ''));
    if ($endpoint === '') {
        sub_respond(400, ['ok' => false, 'error' => 'Endpunkt fehlt.']);
    }
    nb_push_unsubscribe($endpoint);
    sub_respond(200, ['ok' => true]);
}
$sub = nb_push_clean_subscription($in['subscription'] ?? null);
if ($sub === null) {
    sub_respond(400, ['ok' => false, 'error' => 'Abo unvollstaendig.']);
}
$ok = nb_push_subscribe($sub, (string) ($in['heimat'] ?? ''), (string) ($in['zeit'] ?? ''), (string) ($in['alt'] ?? ''));
if ($ok === false) {
    sub_respond(503, ['ok' => false, 'error' => 'Abo konnte nicht gespeichert werden.']);
}
sub_respond(200, ['ok' => true]);
