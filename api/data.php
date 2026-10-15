<?php
/**
 * Liefert die Daten des Nordbahn Monitors als JSON.
 *
 * URL: /api/data
 *
 * Die Startseite bekommt den ersten Stand direkt im HTML und holt sich
 * hier alle paar Minuten einen frischen. Die Daten stammen aus dem letzten
 * Push von n8n (siehe push.php); ohne Push ist received_at null.
 * Eine Minute Browser-Cache reicht, n8n liefert ohnehin nur alle fuenf.
 */

require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=20');
header('X-Robots-Tag: noindex');

echo json_encode(nordbahn_dataset(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
