<?php

namespace App\Lib\Ssl;

use App\System\Project\Domain;

/**
 * What a certificate actually is, in the words something can act on.
 *
 * The parsed certificate already carried the facts — issuer, validity window,
 * the names it covers — but every one of them had to be interpreted before it
 * meant anything: a caller had to compare the issuer's common name to the
 * subject's to learn that a certificate was self-signed, and turn a
 * `not_after` unix timestamp into "expires when". A caller that only wants to
 * say whether the site has a real certificate should not have to do
 * certificate arithmetic to find out, and one that guesses will report
 * "issued SSL" for the self-signed certificate the engine generates for every
 * project domain.
 *
 * `status` is the single word for that: the worst true thing about the
 * certificate, so a caller that reads nothing else is not misled. The
 * booleans and dates stay beside it for a caller that wants to be precise.
 *
 * No Laravel dependencies — unit-testable.
 */
final class CertificateStatus
{
    /** Signed by a certificate authority, in date, and covers the domain. */
    public const TRUSTED = 'trusted';

    /** Its own issuer: browsers reject it. What the engine generates. */
    public const SELF_SIGNED = 'self_signed';

    /**
     * Signed by an authority, and by one this host does not trust: a staging
     * CA, or a private one whose root is not installed. Kept apart from
     * `trusted` because it looks exactly like it from the issuer's name, and
     * apart from `self_signed` because the fix is different -- install the
     * root, or issue from the live authority.
     */
    public const UNTRUSTED_ISSUER = 'untrusted_issuer';

    public const EXPIRED = 'expired';

    public const NOT_YET_VALID = 'not_yet_valid';

    /** In date and properly signed, but not for the domain being served. */
    public const DOMAIN_MISMATCH = 'domain_mismatch';

    /** Present but its validity window could not be read. */
    public const UNREADABLE = 'unreadable';

    private const DAY = 86400;

    /**
     * @param array{
     *   common_name?: string,
     *   issuer_name?: string,
     *   issuer_common_name?: string,
     *   not_before?: int|null,
     *   not_after?: int|null,
     *   domains?: list<string>,
     *   chain_trusted?: ?bool,
     * } $cert as {@see Domain::getSslCertificateInfo()} returns it
     * @param  string  $domain  the name actually being served
     * @param  int|null  $now  unix time, for tests
     * @return array{
     *   status: string,
     *   issuer: string,
     *   self_signed: bool,
     *   chain_trusted: ?bool,
     *   covers_domain: bool,
     *   valid_from: ?string,
     *   expires_at: ?string,
     *   days_remaining: ?int,
     * }
     */
    public static function of(array $cert, string $domain, ?int $now = null): array
    {
        $now ??= time();
        $notBefore = self::timestamp($cert['not_before'] ?? null);
        $notAfter = self::timestamp($cert['not_after'] ?? null);
        $selfSigned = self::isSelfSigned($cert);
        $covers = self::covers($cert['domains'] ?? [], $domain);
        $chainTrusted = $cert['chain_trusted'] ?? null;
        $chainTrusted = is_bool($chainTrusted) ? $chainTrusted : null;

        return [
            'status' => self::verdict($notBefore, $notAfter, $selfSigned, $covers, $now, $chainTrusted),
            'issuer' => self::issuer($cert),
            'self_signed' => $selfSigned,
            'chain_trusted' => $chainTrusted,
            'covers_domain' => $covers,
            'valid_from' => self::iso($notBefore),
            'expires_at' => self::iso($notAfter),
            // Rounded down, so "0 days remaining" is the last day rather than
            // a certificate that has already stopped working.
            'days_remaining' => $notAfter === null ? null : intdiv($notAfter - $now, self::DAY),
        ];
    }

    /**
     * The one name for a certificate whose problems may overlap. Ordered by
     * what a reader most needs to hear: a certificate can be self-signed *and*
     * expired, and "expired" is the more urgent half.
     */
    private static function verdict(
        ?int $notBefore,
        ?int $notAfter,
        bool $selfSigned,
        bool $covers,
        int $now,
        ?bool $chainTrusted = null
    ): string {
        if ($notAfter === null || $notBefore === null) {
            return self::UNREADABLE;
        }
        if ($now > $notAfter) {
            return self::EXPIRED;
        }
        if ($now < $notBefore) {
            return self::NOT_YET_VALID;
        }
        if (! $covers) {
            return self::DOMAIN_MISMATCH;
        }

        if ($selfSigned) {
            return self::SELF_SIGNED;
        }

        // Only a checked failure downgrades it. `null` is "no root store to
        // ask", which must keep reading as it did rather than turning every
        // certificate on a bare image into a warning.
        return $chainTrusted === false ? self::UNTRUSTED_ISSUER : self::TRUSTED;
    }

    /**
     * A certificate that issued itself. Only the common names are parsed out
     * of the two distinguished names, which is enough here: an authority's
     * common name is its own ("R11", "E5"), never the subject's.
     *
     * @param  array<string, mixed>  $cert
     */
    private static function isSelfSigned(array $cert): bool
    {
        $subject = trim((string) ($cert['common_name'] ?? ''));
        $issuer = trim((string) ($cert['issuer_common_name'] ?? ''));

        return $subject !== '' && $issuer !== '' && strcasecmp($subject, $issuer) === 0;
    }

    /**
     * @param  array<string, mixed>  $cert
     */
    private static function issuer(array $cert): string
    {
        foreach (['issuer_name', 'issuer_common_name'] as $key) {
            $value = trim((string) ($cert[$key] ?? ''));
            if ($value !== '' && strcasecmp($value, 'Unknown') !== 0) {
                return $value;
            }
        }

        return 'Unknown';
    }

    /**
     * Whether the certificate is for the name being served. Wildcards cover
     * one label and only at the front, which is the rule browsers apply:
     * `*.example.com` is `app.example.com` and neither `example.com` nor
     * `a.b.example.com`.
     *
     * @param  mixed  $names
     */
    private static function covers($names, string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || ! is_array($names)) {
            return false;
        }

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $name = strtolower(trim($name));
            if ($name === $domain) {
                return true;
            }
            if (! str_starts_with($name, '*.')) {
                continue;
            }
            $suffix = substr($name, 1);
            if (str_ends_with($domain, $suffix)
                && ! str_contains(substr($domain, 0, -strlen($suffix)), '.')
                && strlen($domain) > strlen($suffix)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $value
     */
    private static function timestamp($value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function iso(?int $timestamp): ?string
    {
        return $timestamp === null
            ? null
            : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
