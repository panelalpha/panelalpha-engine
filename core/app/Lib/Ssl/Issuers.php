<?php

namespace App\Lib\Ssl;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Which issuer a domain gets, and what happens when it cannot deliver.
 *
 * Three settings, and the defaults keep a stock engine behaving exactly as it
 * did before this existed:
 *
 *   ssl_issuer          self_signed (default) | acme
 *   acme_directory_url  the ACME v2 directory; `staging` is a shorthand for
 *                       Let's Encrypt's staging CA, and any other RFC 8555
 *                       authority is just its URL
 *   acme_email          ACME account contact; falls back to `cert_email`,
 *                       then the engine's `email`
 *
 * **A certificate is issued once per domain, not once per deploy.**
 * {@see Domain::rebuild()} asks for one only when the domain has none, so a
 * failed attempt leaves a self-signed certificate behind and the next deploy
 * does not stall on the authority again. Getting the real one after the DNS
 * finally points here is `ssl:project-cert:request`, and keeping it is
 * `ssl:project-cert:renew`.
 *
 * The fallback is not optional and not configurable. A site with no
 * certificate does not answer on :443 at all, so "could not obtain a real
 * one" must never mean "serve nothing" — it means serve the self-signed one
 * and say so, which is what `details.ssl` then reports.
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
     * Whether this engine may issue for a name on a zone the fleet shares.
     *
     * Off by default, and that is the whole point of it being a setting.
     * `panelalpha.direct` and its neighbours are one registered domain for
     * every engine there is, and Let's Encrypt counts new certificates per
     * registered domain: 50 a week, fleet-wide. An engine that issues per
     * project there is spending an allowance the rest of the fleet draws on,
     * including for their own admin certificates -- so it is a decision an
     * operator makes on purpose, once, rather than a side effect of turning
     * `ssl_issuer` on for the customer domains they actually own.
     *
     * A domain the customer owns is unaffected: it is their registered
     * domain, with their own limit.
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
     * Issue for the domain with the configured issuer, falling back to a
     * self-signed certificate rather than leaving the site without one.
     *
     * Returns the id of the issuer that actually produced the certificate, so
     * a caller can say which it got.
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
            // Not a failure: the name was never going to pass, and saying so
            // costs nothing while attempting it would spend a rate limit that
            // counts failures.
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

    /**
     * The directory the ACME client talks to. `staging` is spelled out
     * because typing Let's Encrypt's staging URL from memory is how a test
     * accidentally spends production rate limit.
     */
    public static function directoryUrl(): string
    {
        $configured = trim((string) Setting::get('acme_directory_url'));

        return match (strtolower($configured)) {
            '' , 'live', 'production' => AcmeIssuer::LETS_ENCRYPT,
            'staging', 'test' => AcmeIssuer::LETS_ENCRYPT_STAGING,
            default => $configured,
        };
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
