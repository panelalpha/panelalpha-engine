<?php

namespace App\Lib\Ssl;

/**
 * Who signed a certificate and what it is made of: the parts of a parsed
 * certificate that {@see CertificateFacts} leaves out because the verdict
 * does not need them, and a person reading the certificate does.
 *
 * No Laravel dependencies — unit-testable.
 */
final class CertificateDetails
{
    private const KEY_TYPES = [
        OPENSSL_KEYTYPE_RSA => 'RSA',
        OPENSSL_KEYTYPE_DSA => 'DSA',
        OPENSSL_KEYTYPE_DH => 'DH',
        OPENSSL_KEYTYPE_EC => 'EC',
    ];

    /**
     * @return array{
     *   subject: array{common_name: ?string, organization: ?string, country: ?string},
     *   signer: array{common_name: ?string, organization: ?string, country: ?string},
     *   serial_number: ?string,
     *   signature_algorithm: ?string,
     *   key: array{type: ?string, bits: ?int, curve: ?string},
     *   valid_from: ?string,
     *   expires_at: ?string,
     * }|null null when the PEM is not a certificate this can read
     */
    public static function of(string $pem): ?array
    {
        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed)) {
            return null;
        }

        return [
            'subject' => self::name($parsed['subject'] ?? null),
            'signer' => self::name($parsed['issuer'] ?? null),
            'serial_number' => self::text($parsed['serialNumberHex'] ?? null),
            'signature_algorithm' => self::text($parsed['signatureTypeLN'] ?? null),
            'key' => self::key($pem),
            'valid_from' => self::iso($parsed['validFrom_time_t'] ?? null),
            'expires_at' => self::iso($parsed['validTo_time_t'] ?? null),
        ];
    }

    /**
     * @return array{common_name: ?string, organization: ?string, country: ?string}
     */
    private static function name(mixed $dn): array
    {
        $dn = is_array($dn) ? $dn : [];

        return [
            'common_name' => self::text($dn['CN'] ?? null),
            'organization' => self::text($dn['O'] ?? null),
            'country' => self::text($dn['C'] ?? null),
        ];
    }

    /**
     * @return array{type: ?string, bits: ?int, curve: ?string}
     */
    private static function key(string $pem): array
    {
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! is_array($details)) {
            return ['type' => null, 'bits' => null, 'curve' => null];
        }

        return [
            'type' => self::KEY_TYPES[$details['type'] ?? -1] ?? null,
            'bits' => is_int($details['bits'] ?? null) ? $details['bits'] : null,
            'curve' => self::text($details['ec']['curve_name'] ?? null),
        ];
    }

    /** A DN may repeat a field, which openssl gives as a list; the first one names it. */
    private static function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function iso(mixed $timestamp): ?string
    {
        return is_int($timestamp) ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }
}
