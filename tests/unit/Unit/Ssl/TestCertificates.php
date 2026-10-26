<?php

namespace Tests\Unit\Ssl;

/**
 * Certificates built in-process for the SSL tests: self-signed, or signed by a
 * throwaway authority, with real subjectAltNames.
 */
final class TestCertificates
{
    /**
     * @param  list<string>  $names
     * @return array{cert: string, key: string}
     */
    public static function selfSigned(array $names, int $days = 30): array
    {
        $key = self::key();
        $cert = self::sign($names, $key, null, $key, $days);

        return ['cert' => $cert, 'key' => self::export($key)];
    }

    /**
     * @return array{cert: string, key: \OpenSSLAsymmetricKey}
     */
    public static function authority(string $name = 'Throwaway Test Root'): array
    {
        $key = self::key();
        $csr = openssl_csr_new(['commonName' => $name, 'organizationName' => $name], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256', 'x509_extensions' => 'v3_ca'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);

        return ['cert' => $pem, 'key' => $key];
    }

    /**
     * @param  list<string>  $names
     * @param  array{cert: string, key: \OpenSSLAsymmetricKey}  $authority
     */
    public static function signedBy(array $names, array $authority): string
    {
        return self::sign($names, self::key(), $authority['cert'], $authority['key'], 30);
    }

    /**
     * @param  list<string>  $names
     */
    private static function sign(array $names, \OpenSSLAsymmetricKey $key, ?string $issuer, \OpenSSLAsymmetricKey $issuerKey, int $days): string
    {
        $config = self::configWithNames($names);
        try {
            $options = ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'leaf'];
            $csr = openssl_csr_new(['commonName' => $names[0]], $key, $options);
            $cert = openssl_csr_sign($csr, $issuer, $issuerKey, $days, $options, random_int(1, PHP_INT_MAX));
            openssl_x509_export($cert, $pem);
        } finally {
            @unlink($config);
        }

        return $pem;
    }

    /**
     * @param  list<string>  $names
     */
    private static function configWithNames(array $names): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pa-ssl-test-');
        $alt = implode(',', array_map(static fn (string $name): string => "DNS:{$name}", $names));
        file_put_contents($path, "[req]\ndistinguished_name = dn\n[dn]\n[leaf]\nsubjectAltName = {$alt}\nbasicConstraints = CA:FALSE\n");

        return $path;
    }

    private static function key(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        assert($key instanceof \OpenSSLAsymmetricKey);

        return $key;
    }

    private static function export(\OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
