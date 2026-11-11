<?php

namespace App\Lib\Ssl;

use App\Models\Setting;
use App\System\Project\Domain;
use Illuminate\Support\Facades\Log;

/**
 * Which issuer a domain gets (`ssl_issuer`, self_signed by default so a stock engine behaves as before), once per domain as
 * {@see Domain::rebuild()} enforces; a failure leaves a self-signed one until `ssl:project-cert:request`: no certificate, no :443.
 */
final class Issuers
{
    /**
     * The issuer the settings select, without the fallback around it.
     */
    public static function configured(): Issuer
    {
        $id = strtolower(trim((string) (Setting::get('ssl_issuer') ?: SelfSignedIssuer::ID)));

        if ($id !== AcmeIssuer::ID) {
            return new SelfSignedIssuer;
        }

        return new AcmeIssuer(
            self::directoryUrl(),
            self::email(),
            null,
            null,
            self::sharedZoneIssuanceAllowed()
        );
    }

    /**
     * Whether this engine may issue on a fleet-shared zone; off unless an operator opts in, as Let's Encrypt allows 50 a week
     * per registered domain, fleet-wide and admin certificates included. A customer's own domain has its own limit.
     */
    public static function sharedZoneIssuanceAllowed(): bool
    {
        return filter_var(
            Setting::get('ssl_shared_zone_issuance'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) === true;
    }

    /**
     * Issue with the configured issuer, falling back to self-signed rather than leave the site without one; answers the
     * producing issuer's id. An ineligible name is only logged, as trying would spend a rate limit that counts failures.
     */
    public static function issueFor(IssuableDomain $domain): string
    {
        $issuer = self::configured();

        if ($issuer instanceof SelfSignedIssuer) {
            $issuer->issue($domain);

            return $issuer->id();
        }

        $name = $domain->model()->domain;
        $reason = $issuer->ineligibleReason($name);

        if ($reason !== null) {
            Log::info("Not requesting a certificate for {$name}: {$reason}");

            return self::selfSign($domain);
        }

        try {
            $issuer->issue($domain);
            Log::info("Issued a certificate for {$name} via {$issuer->id()}");

            return $issuer->id();
        } catch (\Throwable $e) {
            Log::warning("Could not issue a certificate for {$name}: ".$e->getMessage());

            return self::selfSign($domain);
        }
    }

    /** The words `acme_directory_url` may hold in place of a URL, and the directory each names. */
    public const DIRECTORY_SHORTHANDS = [
        'live' => AcmeIssuer::LETS_ENCRYPT,
        'production' => AcmeIssuer::LETS_ENCRYPT,
        'staging' => AcmeIssuer::LETS_ENCRYPT_STAGING,
        'test' => AcmeIssuer::LETS_ENCRYPT_STAGING,
    ];

    /**
     * The directory the ACME client talks to; `staging` is a shorthand because typing Let's Encrypt's staging URL from
     * memory is how a test accidentally spends production rate limit.
     */
    public static function directoryUrl(): string
    {
        $configured = trim((string) Setting::get('acme_directory_url'));

        if ($configured === '') {
            return AcmeIssuer::LETS_ENCRYPT;
        }

        return self::DIRECTORY_SHORTHANDS[strtolower($configured)] ?? $configured;
    }

    /** Whether the value names one of the shorthands rather than a directory URL. */
    public static function isDirectoryShorthand(string $value): bool
    {
        return array_key_exists(strtolower(trim($value)), self::DIRECTORY_SHORTHANDS);
    }

    public static function email(): ?string
    {
        foreach (['acme_email', 'cert_email', 'email'] as $setting) {
            $value = trim((string) Setting::get($setting));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return $value;
            }
        }

        return null;
    }

    private static function selfSign(IssuableDomain $domain): string
    {
        $fallback = new SelfSignedIssuer;
        $fallback->issue($domain);

        return $fallback->id();
    }
}
