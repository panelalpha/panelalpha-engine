<?php

namespace App\Lib\Ssl;

/**
 * Whether a certificate and a private key are a pair.
 *
 * This is the one thing a certificate file can be wrong about that nginx will
 * not complain about. Given a cert and a key that do not belong together,
 * `nginx -t` passes, nginx starts, logs nothing to alarm anyone, and then
 * refuses *every* TLS handshake with `SSL alert number 40` — no peer
 * certificate, no useful message. When that pair is the engine's own
 * `crt/server.cert` + `crt/server.key`, the whole control-plane API on :2011
 * goes dark: no `GET /system/info`, no MCP, no deploys. It looks like a
 * network fault and is a file mismatch.
 *
 * Measured on 2.29.1.58 after a certificate rotation:
 *
 *     server.cert      + server.key      -> MISMATCH   (cert CN=172.31.66.10)
 *     server.cert.bak  + server.key.bak  -> MATCH       (CN=2.29.1.58)
 *
 * `le_install_lineage()` copies a lineage's `fullchain.pem` and `privkey.pem`
 * over those two files and reloads `core`'s nginx. It backs the old pair up
 * first, and that backup is what made the mismatch recoverable — but nothing
 * between the copy and the reload notices that the new pair does not go
 * together, so the reload is what takes the API down rather than the copy.
 *
 * Compared by public key rather than by hash of the file: the certificate
 * holds the public half, the key file holds the private half, and the two are
 * the same key exactly when their public parts are equal. That is also why a
 * leaf certificate and its issuer's key are correctly *not* a pair.
 *
 * No Laravel dependencies — unit-testable.
 */
final class KeyPair
{
    /**
     * Whether $certificatePem and $privateKeyPem belong together.
     *
     * False for anything that cannot be read as the right kind of PEM, so an
     * unreadable file is a mismatch rather than a pass. Null is not returned:
     * the caller has to decide what to do about a mismatch, and "cannot tell"
     * has the same consequence as "does not match" here — do not serve it.
     */
    public static function matches(string $certificatePem, string $privateKeyPem): bool
    {
        $certificate = self::publicKeyOfCertificate($certificatePem);
        $private = self::publicKeyOfPrivateKey($privateKeyPem);

        if ($certificate === null || $private === null) {
            return false;
        }

        return hash_equals($certificate, $private);
    }

    /**
     * The public key a certificate carries, as openssl's own encoding.
     */
    public static function publicKeyOfCertificate(string $pem): ?string
    {
        if (trim($pem) === '' || !str_contains($pem, 'CERTIFICATE')) {
            return null;
        }

        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }

        $details = @openssl_pkey_get_details($key);

        return is_array($details) && isset($details['key']) && is_string($details['key'])
            ? trim($details['key'])
            : null;
    }

    /**
     * The public half of a private key, in the same encoding, so the two
     * compare equal when they are a pair.
     */
    public static function publicKeyOfPrivateKey(string $pem): ?string
    {
        if (trim($pem) === '' || !preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $pem)) {
            return null;
        }

        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }

        $details = @openssl_pkey_get_details($key);

        return is_array($details) && isset($details['key']) && is_string($details['key'])
            ? trim($details['key'])
            : null;
    }
}
