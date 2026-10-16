<?php
/** Oeffentlicher VAPID-Schluessel fuer den Service Worker (pushsubscriptionchange). */
require __DIR__ . '/store.php';
require __DIR__ . '/notify.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo json_encode(['key' => nb_push_public_key()]);
