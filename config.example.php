<?php
/**
 * Vorlage fuer die Konfiguration.
 *
 * Kopie als config.php ins Web-Root legen (per SFTP). Die Datei wird weder
 * committet noch deployt, der Mirror schliesst sie aus; sie ueberlebt also
 * jeden Deploy. Ruft jemand config.php im Browser auf, verbietet .htaccess
 * den Zugriff, und selbst ohne .htaccess liefert PHP nur eine leere Antwort.
 */

return [
    // Frei gewaehlter Wert, der identisch in den n8n-Workflows "Nordbahn
    // Dashboard Push" (Header X-Nordbahn-Token) und "Strecken-Info Stoerungen
    // Alert" (?token= beim Revision-Endpunkt) eingetragen ist. Ohne diesen Wert
    // nimmt api/push nichts an.
    'NORDBAHN_PUSH_TOKEN' => '',

    // Oeffentliche Adresse ohne Slash am Ende. Canonical-Links, Open Graph,
    // Sitemap und robots.txt bauen darauf auf. Leer: der aufgerufene Hostname.
    'NORDBAHN_SITE_URL' => 'https://bahn-pinneberg.de',

    // Script-URL der Plausible-Site fuer diese Domain. Leer: kein Analytics.
    'NORDBAHN_PLAUSIBLE_JS' => '',
];
