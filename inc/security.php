<?php
/**
 * Security-Header fuer die HTML-Seiten. Die statischen Header (HSTS, nosniff,
 * Referrer-Policy, Permissions-Policy) setzt die .htaccess fuer alles; die
 * Content-Security-Policy kommt von hier, weil sie je Antwort eine Nonce fuer
 * das Analytics-Snippet braucht. Eigene Scripts und Styles liegen auf dem
 * eigenen Host, Inline-Styles und Event-Handler gibt es nicht.
 */

/** Nonce dieser Antwort, einmal erzeugt. */
function nb_csp_nonce()
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    return $nonce;
}

/**
 * Schickt die CSP, sofern noch keine Ausgabe begonnen hat. $extra erlaubt einer
 * Seite weitere Quellen je Direktive, z. B. ['img-src' => 'https://tile.openstreetmap.org']
 * fuer die Kartenkacheln der Live-Karte.
 */
function nb_send_security_headers(array $extra = [])
{
    if (headers_sent()) {
        return;
    }
    $plausible = nordbahn_plausible_js();
    $plausibleOrigin = '';
    if ($plausible !== '') {
        $p = parse_url($plausible);
        if (!empty($p['scheme']) && !empty($p['host'])) {
            $plausibleOrigin = ' ' . $p['scheme'] . '://' . $p['host'];
        }
    }
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'nonce-" . nb_csp_nonce() . "'" . $plausibleOrigin,
        "style-src 'self'",
        "img-src 'self' data:" . (isset($extra['img-src']) ? ' ' . $extra['img-src'] : ''),
        "font-src 'self'",
        "connect-src 'self'" . $plausibleOrigin,
        "manifest-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
        "object-src 'none'",
        'upgrade-insecure-requests',
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}
