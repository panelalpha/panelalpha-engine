<?php

namespace App\Lib\Ssl;

/**
 * Whether the host's own root store trusts a certificate: null when it cannot be asked,
 * since "could not check" and "checked and it fails" must stay apart.
 */
final class TrustStore
{
    /**
     * Whether a chain leads to a root this host trusts.
     *
     * @param  string  $pem  the leaf, optionally followed by its intermediates
     * @return ?bool null when the chain could not be checked; -1 from openssl is not "untrusted"
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

        $result = self::checkPurpose($leaf, $bundle, $untrusted);

        return $result === true ? true : ($result === false ? false : null);
    }

    /** Like `openssl verify -untrusted`: the leaf, with the intermediates as untrusted candidates. */
    private static function checkPurpose(string $leaf, string $bundle, ?string $untrusted): bool|int
    {
        try {
            return @openssl_x509_checkpurpose(
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
    }

    /**
     * The intermediates in a temp file, or null when they cannot be written in full.
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

    /** The root store openssl was built to use, when readable: a file, since the hashed directory is often empty. */
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
