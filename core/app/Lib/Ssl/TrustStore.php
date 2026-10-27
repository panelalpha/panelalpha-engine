<?php

namespace App\Lib\Ssl;

/**
 * Does anything actually trust this certificate?
 *
 * `status: trusted` used to mean "not self-signed", which is a different
 * question and sometimes the opposite answer. A certificate from Let's
 * Encrypt's *staging* authority is signed by a CA, in date, and for the right
 * name -- and every client on earth refuses it. The engine reported it as
 * `trusted (issuer: Let's Encrypt)` while curl was answering
 * `SSL certificate problem: unable to get local issuer certificate`. Anyone
 * testing an issuance path against staging, which is the responsible way to
 * test one, was told it had worked.
 *
 * So the question is asked of the host's own root store rather than inferred
 * from a name. Null where it cannot be asked -- no CA bundle on the box, an
 * openssl build without the call, intermediates that cannot be written out
 * for it -- because "we could not check" and "we checked and it fails" must
 * not collapse into one answer.
 *
 * No Laravel dependencies — unit-testable.
 */
final class TrustStore
{
    /**
     * Whether a chain leads to a root this host trusts.
     *
     * @param  string  $pem  the leaf, optionally followed by its intermediates
     * @return ?bool null when the chain could not be checked
     */
    public static function verifies(string $pem, ?string $caBundle = null): ?bool
    {
        if (trim($pem) === '' || ! function_exists('openssl_x509_checkpurpose')) {
            return null;
        }

        $bundle = $caBundle ?? self::defaultBundle();
        if ($bundle === null) {
            return null;
        }

        // A chain in one file is the leaf plus its intermediates; checkpurpose
        // wants the leaf and is given the rest as untrusted candidates, which
        // is exactly what `openssl verify -untrusted` does.
        $blocks = self::blocks($pem);
        if ($blocks === []) {
            return null;
        }

        $leaf = array_shift($blocks);
        $untrusted = null;
        if ($blocks !== []) {
            $untrusted = self::writeIntermediates($blocks);
            if ($untrusted === null) {
                return null;
            }
        }

        try {
            $result = @openssl_x509_checkpurpose(
                $leaf,
                X509_PURPOSE_SSL_SERVER,
                [$bundle],
                $untrusted
            );
        } finally {
            if ($untrusted !== null) {
                @unlink($untrusted);
            }
        }

        // -1 is "the check itself failed", which is not the same as "untrusted".
        return $result === true ? true : ($result === false ? false : null);
    }

    /**
     * The intermediates in a temp file, or null when they cannot be written in
     * full: the leaf checked without them would read as untrusted.
     *
     * @param  list<string>  $blocks
     */
    private static function writeIntermediates(array $blocks): ?string
    {
        $path = @tempnam(sys_get_temp_dir(), 'pa-chain-');
        if (! is_string($path)) {
            return null;
        }

        $chain = implode(PHP_EOL, $blocks).PHP_EOL;
        if (@file_put_contents($path, $chain) !== strlen($chain)) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * The root store openssl was built to use, when it is readable.
     *
     * A file, not a directory: `checkpurpose` takes either, but the hashed
     * directory form is empty on plenty of images while the bundle is there.
     */
    public static function defaultBundle(): ?string
    {
        $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
        $candidates = [
            $locations['default_cert_file'] ?? null,
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string> each PEM certificate in the bundle, in order
     */
    private static function blocks(string $pem): array
    {
        preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $matches
        );

        return array_values(array_map('trim', $matches[0] ?? []));
    }
}
