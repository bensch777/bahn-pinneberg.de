<?php
/**
 * Erzeugt ein VAPID-Schluesselpaar fuer Web Push und gibt die Zeilen fuer
 * config.php aus:  php dev/vapid.php
 */
require __DIR__ . '/../api/webpush.php';
$key = wp_new_keypair();
openssl_pkey_export($key, $pem);
$public = wp_vapid_public_from_private($pem);
echo "    'NORDBAHN_VAPID_PUBLIC' => '", $public, "',\n";
echo "    'NORDBAHN_VAPID_PRIVATE' => <<<'PEM'\n", rtrim($pem), "\nPEM,\n";
echo "    'NORDBAHN_VAPID_SUBJECT' => 'mailto:hallo@bahn-pinneberg.de',\n";
