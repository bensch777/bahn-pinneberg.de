<?php
/** Verifizierungs-Tags fuer Search Console und Bing, aus config.php; ohne Wert nichts. */
$config = nordbahn_config();
foreach (['NORDBAHN_GOOGLE_VERIFICATION' => 'google-site-verification', 'NORDBAHN_BING_VERIFICATION' => 'msvalidate.01'] as $key => $name) {
    $value = trim((string) ($config[$key] ?? ''));
    if ($value !== '') {
        echo '<meta name="', $name, '" content="', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), "\">\n";
    }
}
