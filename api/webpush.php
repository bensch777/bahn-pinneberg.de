<?php
/**
 * Web Push ohne Fremdpakete: VAPID (RFC 8292, ES256-JWT) und die
 * Payload-Verschluesselung aes128gcm (RFC 8291 / RFC 8188) mit den
 * OpenSSL-Funktionen von PHP. Ein Push ist ein POST an den Endpunkt des
 * Browser-Herstellers; der Browser entschluesselt im Service Worker.
 */

function wp_b64url_encode($bin)
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function wp_b64url_decode($s)
{
    $s = strtr((string) $s, '-_', '+/');

    return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** SubjectPublicKeyInfo-Praefix fuer einen unkomprimierten P-256-Punkt (65 Bytes). */
const WP_P256_SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

/** OpenSSL-Schluessel aus einem rohen P-256-Punkt (0x04 || x || y). */
function wp_public_key_from_raw($raw)
{
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
        return null;
    }
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(WP_P256_SPKI_PREFIX . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);

    return $key ?: null;
}

/** Roher P-256-Punkt eines OpenSSL-Schluessels (privat oder oeffentlich). */
function wp_raw_public_key($key)
{
    $d = openssl_pkey_get_details($key);
    if (empty($d['ec']['x']) || empty($d['ec']['y'])) {
        return null;
    }

    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function wp_new_keypair()
{
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/**
 * Verschluesselt einen Klartext fuer ein Abo (p256dh = Browser-Schluessel,
 * auth = Geheimnis, beide base64url). Liefert den fertigen Request-Body
 * (Header mit Salt und Server-Schluessel, dann ein Record) oder null.
 */
function wp_encrypt($p256dh, $auth, $payload)
{
    $uaPub = wp_b64url_decode($p256dh);
    $authSecret = wp_b64url_decode($auth);
    if (strlen($uaPub) !== 65 || strlen($authSecret) !== 16 || strlen($payload) > 3900) {
        return null;
    }
    $uaKey = wp_public_key_from_raw($uaPub);
    $local = wp_new_keypair();
    if ($uaKey === null || $local === false) {
        return null;
    }
    $asPub = wp_raw_public_key($local);
    $shared = openssl_pkey_derive($uaKey, $local, 32);
    if ($asPub === null || $shared === false) {
        return null;
    }
    $salt = random_bytes(16);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    // Ein Record: Klartext, Trennzeichen 0x02 (letzter Record), dann der GCM-Tag
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) {
        return null;
    }

    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/** DER-Signatur (SEQUENCE { r, s }) in das rohe r||s-Format (64 Bytes) fuer JWS. */
function wp_der_sig_to_raw($der)
{
    $pos = 1;
    $len = ord($der[$pos++]);
    if ($len & 0x80) {
        $pos += $len & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (($der[$pos] ?? '') !== "\x02") {
            return null;
        }
        $l = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $l), "\0");
        $pos += 2 + $l;
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }

    return strlen($out) === 64 ? $out : null;
}

/** Umkehrung von wp_der_sig_to_raw, fuer openssl_verify. */
function wp_raw_sig_to_der($raw)
{
    $enc = function ($int) {
        $int = ltrim($int, "\0");
        if ($int === '' || ord($int[0]) & 0x80) {
            $int = "\0" . $int;
        }

        return "\x02" . chr(strlen($int)) . $int;
    };
    $body = $enc(substr($raw, 0, 32)) . $enc(substr($raw, 32));

    return "\x30" . chr(strlen($body)) . $body;
}

/** VAPID-Token (JWT, ES256) fuer den Push-Dienst unter $aud (Scheme + Host). */
function wp_vapid_jwt($aud, $subject, $privatePem, $exp)
{
    $key = openssl_pkey_get_private($privatePem);
    if (!$key) {
        return null;
    }
    $header = wp_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = wp_b64url_encode(json_encode(['aud' => $aud, 'exp' => (int) $exp, 'sub' => $subject]));
    $der = '';
    if (!openssl_sign($header . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $raw = wp_der_sig_to_raw($der);

    return $raw === null ? null : $header . '.' . $claims . '.' . wp_b64url_encode($raw);
}

/** Oeffentlicher VAPID-Schluessel (base64url, roh) zu einem privaten PEM. */
function wp_vapid_public_from_private($privatePem)
{
    $key = openssl_pkey_get_private($privatePem);
    if (!$key) {
        return null;
    }
    $raw = wp_raw_public_key($key);

    return $raw === null ? null : wp_b64url_encode($raw);
}

/**
 * Schickt eine Nachricht an ein Abo. $sub: endpoint, p256dh, auth.
 * $vapid: private_pem, public (base64url), subject (mailto:...).
 * Liefert ['status' => HTTP-Code (0 = kein Versand), 'error' => Text].
 */
function wp_send(array $sub, $payload, array $vapid, $ttl = 3600, $urgency = 'high')
{
    $body = wp_encrypt($sub['p256dh'] ?? '', $sub['auth'] ?? '', $payload);
    if ($body === null) {
        return ['status' => 0, 'error' => 'Verschluesselung fehlgeschlagen'];
    }
    $u = parse_url((string) ($sub['endpoint'] ?? ''));
    if (empty($u['scheme']) || empty($u['host']) || $u['scheme'] !== 'https') {
        return ['status' => 0, 'error' => 'Endpunkt ungueltig'];
    }
    $aud = 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $jwt = wp_vapid_jwt($aud, $vapid['subject'], $vapid['private_pem'], time() + 12 * 3600);
    if ($jwt === null) {
        return ['status' => 0, 'error' => 'VAPID-Signatur fehlgeschlagen'];
    }
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Content-Length: ' . strlen($body),
        'TTL: ' . (int) $ttl,
        'Urgency: ' . $urgency,
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
    ];
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'error' => 'curl fehlt'];
    }
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $response === false ? curl_error($ch) : '';
    curl_close($ch);

    return ['status' => $status, 'error' => $err !== '' ? $err : ($status >= 400 ? trim((string) substr((string) $response, 0, 200)) : '')];
}
