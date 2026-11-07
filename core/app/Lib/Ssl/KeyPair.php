<?php

namespace App\Lib\Ssl;

/**
 * Whether a certificate and a private key are a pair, by public key: a mismatched pair passes `nginx -t` but
 * refuses every handshake (`SSL alert number 40`), which on core's :2011 certificate takes the whole API down.
 */
final class KeyPair
{
    /**
     * Whether $certificatePem and $privateKeyPem belong together. Anything unreadable is a mismatch, since
     * "cannot tell" has the same consequence as "does not match": do not serve it.
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
        if (trim($pem) === '' || ! str_contains($pem, 'CERTIFICATE')) {
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
        if (trim($pem) === '' || ! preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $pem)) {
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
