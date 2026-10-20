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

    // Web Push: VAPID-Schluesselpaar, einmal erzeugen mit  php dev/vapid.php
    // und beide Werte hier eintragen. Ohne privaten Schluessel ist Web Push aus,
    // die Seite meldet dann nur bei geoeffnetem Tab. SUBJECT ist die Kontakt-
    // adresse fuer die Push-Dienste (mailto:...).
    'NORDBAHN_VAPID_PUBLIC' => '',
    'NORDBAHN_VAPID_PRIVATE' => '',
    'NORDBAHN_VAPID_SUBJECT' => 'mailto:hallo@bahn-pinneberg.de',

    // Verifizierungs-Codes fuer Google Search Console und Bing Webmaster Tools
    // (nur der content-Wert des Meta-Tags). Leer: kein Tag.
    // HVV-Schnittstelle Geofox GTI (Zugang bei der HBT beantragen). Mit Nutzer und
    // Passwort zeigt die Startseite die Karte "S3 Pinneberg – Hamburg" und die
    // HVV-Meldungen zu S3, RB61 und RB71. Leer: beides bleibt aus.
    'NORDBAHN_GEOFOX_USER'     => '',
    'NORDBAHN_GEOFOX_PASSWORD' => '',
    // Optional: andere Basisadresse (Standard https://gti.geofox.de/gti/public) und
    // Protokollversion, falls der Zugang eine andere verlangt (Standard 59).
    'NORDBAHN_GEOFOX_URL'      => '',
    'NORDBAHN_GEOFOX_VERSION'  => '',

    'NORDBAHN_GOOGLE_VERIFICATION' => '',
    'NORDBAHN_BING_VERIFICATION' => '',

    // Angaben fuer das Impressum, optional: ueberschreibt die Vorgabe in
    // impressum/index.php Feld fuer Feld (name, zusatz, strasse, ort, land,
    // email, hoster). 'hoster' erscheint in der Datenschutzerklaerung.
    'NORDBAHN_IMPRESSUM' => [
        'hoster' => '',
    ],
];
