<?php
/**
 * Fehlerseite fuer alles, was Apache selbst ablehnt (ErrorDocument in der
 * .htaccess, im eingebauten PHP-Server dev/router.php). Statuscode aus
 * REDIRECT_STATUS; Endpunkte unter /api/ und /mcp bekommen JSON (inc/fehler.php).
 */

require_once __DIR__ . '/../inc/fehler.php';
nb_fehler_antwort();
