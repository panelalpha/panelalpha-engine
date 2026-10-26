<?php

namespace App\Lib\Ssl;

use App\System\Project\Domain;

/**
 * A certificate read out of its PEM, in the shape the rest of this namespace
 * expects.
 *
 * Pulled out of {@see Domain::getSslCertificateInfo()}
 * because two places now need to know what a certificate says and only one of
 * them belongs to an account: the other is the engine's own certificate, which
 * a project may be entitled to reuse when it covers the project's name.
 *
 * The subjectAltName parse is the reason this is worth a class. openssl hands
 * it back as one string — `DNS:a.example.com, DNS:*.example.com, IP Address:…`
 * — and a certificate's real name list is that string, not its common name. A
 * wildcard certificate has a common name that matches nothing useful.
 *
 * No Laravel dependencies — unit-testable.
 */
final class CertificateFacts
{
    /**
     * @param  string  $pem  the leaf certificate
     * @param  ?string  $intermediates  the rest of the chain, where it is held
     *                                  separately -- an account keeps the leaf in `<domain>.crt` and
     *                                  its issuers in `<domain>.ca`, and a leaf alone cannot be checked
     *                                  against a root store: openssl has no way to bridge the two.
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
            // Asked of the host's root store rather than guessed from the
            // issuer's name; null when there is no store to ask. See
            // {@see TrustStore}, and the staging certificate that was
            // reported as trusted before it existed.
            'chain_trusted' => TrustStore::verifies(
                $pem.($intermediates === null ? '' : PHP_EOL.$intermediates)
            ),
        ];
    }

    /**
     * Every name the certificate is valid for.
     *
     * The common name is the fallback and not the answer: it is absent from
     * modern certificates, and where it is present it repeats the first
     * subjectAltName entry.
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
     * @param  array<string, mixed>  $parsed
     */
    private static function field(array $parsed, string $section, string $key): string
    {
        $values = $parsed[$section] ?? null;
        if (! is_array($values)) {
            return '';
        }

        $value = $values[$key] ?? null;
        // A DN may carry a field more than once; openssl gives those as a list.
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
