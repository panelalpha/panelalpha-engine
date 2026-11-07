<?php

namespace App\Lib\Ssl;

use App\System;
use RuntimeException;

/**
 * The key the engine signs ACME requests with, made once and kept at 0600 beside its certificates, not in the database:
 * it is the account, so a new one per request would re-spend the "new account" and "new order" limits from zero.
 */
final class AcmeAccountKey
{
    /**
     * EC P-384, smaller and faster than RSA and accepted by every ACME v2 authority, by openssl's short name:
     * `openssl_pkey_new()` reads it from `openssl_get_curve_names()` and rejects "P-384".
     */
    private const CURVE = 'secp384r1';

    private System $system;

    public function __construct(?System $system = null)
    {
        $this->system = $system ?? new System;
    }

    public function path(): string
    {
        return $this->system->engineDirPath().'/crt/acme-account.key';
    }

    /**
     * The stored key, generated and persisted the first time it is asked for.
     */
    public function pem(): string
    {
        $path = $this->path();
        $fs = $this->system->filesystem();

        if ($fs->fileExists($path)) {
            $pem = trim($fs->fileGetContents($path));
            if ($pem !== '') {
                return $pem;
            }
        }

        $pem = self::generate();
        $fs->makeDirWithParents(dirname($path));
        $fs->filePutContents($path, $pem.PHP_EOL, null, '600');

        return $pem;
    }

    /**
     * @throws RuntimeException when openssl cannot produce a key at all —
     *                          there is nothing to fall back to and issuing must not proceed
     *                          with a key that was silently not generated.
     */
    private static function generate(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => self::CURVE,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException(
                'Could not generate an ACME account key: '.(openssl_error_string() ?: 'unknown openssl error')
            );
        }

        return trim($pem);
    }
}
