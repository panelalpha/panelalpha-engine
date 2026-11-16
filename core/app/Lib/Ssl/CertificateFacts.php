<?php

namespace App\Lib\Ssl;

use App\System\Project\Domain;

/**
 * A certificate read out of its PEM, for {@see Domain::getSslCertificateInfo()} and the engine's own
 * certificate; its subjectAltName list, not its common name, is its real name list.
 */
final class CertificateFacts
{
    /**
     * The chain is asked of the host's root store ({@see TrustStore}), never guessed from the issuer.
     *
     * @param  string  $pem  the leaf certificate
     * @param  ?string  $intermediates  the rest of the chain, where it is held separately
     * @return array{
     *   common_name: string,
     *   issuer_name: string,
     *   issuer_common_name: string,
     *   not_before: ?int,
     *   not_after: ?int,
     *   domains: list<string>,
     *   chain_trusted: ?bool,
     * }|null null when the PEM is not a certificate this can read
     */
    public static function fromPem(string $pem, ?string $intermediates = null): ?array
    {
        if (trim($pem) === '') {
            return null;
        }

        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed)) {
            return null;
        }

        $commonName = self::field($parsed, 'subject', 'CN');

        return [
            'common_name' => $commonName,
            'issuer_name' => self::field($parsed, 'issuer', 'O') ?: 'Unknown',
            'issuer_common_name' => self::field($parsed, 'issuer', 'CN'),
            'not_before' => self::timestamp($parsed, 'validFrom_time_t'),
            'not_after' => self::timestamp($parsed, 'validTo_time_t'),
            'domains' => self::names($parsed, $commonName),
            'chain_trusted' => TrustStore::verifies(
                $pem.($intermediates === null ? '' : PHP_EOL.$intermediates)
            ),
        ];
    }

    /**
     * Every name the certificate is valid for; the common name is only the fallback.
     *
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private static function names(array $parsed, string $commonName): array
    {
        $altName = $parsed['extensions']['subjectAltName'] ?? null;

        if (! is_string($altName) || trim($altName) === '') {
            return $commonName === '' ? [] : [$commonName];
        }

        $names = [];
        foreach (explode(',', $altName) as $entry) {
            $entry = trim($entry);
            foreach (['DNS:', 'IP Address:'] as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    $value = trim(substr($entry, strlen($prefix)));
                    if ($value !== '') {
                        $names[] = $value;
                    }
                }
            }
        }

        return $names === [] && $commonName !== '' ? [$commonName] : array_values(array_unique($names));
    }

    /**
     * A DN may carry a field more than once; openssl gives those as a list.
     *
     * @param  array<string, mixed>  $parsed
     */
    private static function field(array $parsed, string $section, string $key): string
    {
        $values = $parsed[$section] ?? null;
        if (! is_array($values)) {
            return '';
        }

        $value = $values[$key] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private static function timestamp(array $parsed, string $key): ?int
    {
        $value = $parsed[$key] ?? null;

        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
