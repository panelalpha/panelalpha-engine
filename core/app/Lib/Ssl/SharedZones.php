<?php

namespace App\Lib\Ssl;

/**
 * The wildcard DNS zones every engine shares, and why a project domain under
 * one of them does not get its own Let's Encrypt certificate.
 *
 * `sslip.io`, `nip.io` and `panelalpha.direct` resolve `<dashed-ip>.<zone>`
 * back to the address in the name, which is what makes a project reachable on
 * a fresh install with no DNS to set up. It also means every engine in the
 * fleet issues under the *same registered domain*, and Let's Encrypt counts
 * new certificates per registered domain: 50 per 7 days, refilling at one per
 * ~202 minutes. None of these zones is on the Public Suffix List and none can
 * be — the PSL guidelines reject wildcard IP-to-name zones explicitly — so
 * there is no boundary for the limit to land on.
 *
 * Fifty projects a week, fleet-wide, is not a per-project certificate
 * mechanism; it is a way to exhaust the limit for everyone including the
 * engines that need it for their own admin certificate. An operator who wants
 * real certificates for the sites points a domain they control at the host and
 * sets it as the base domain. Until then a project domain keeps the
 * certificate the engine signs itself, which costs nothing and breaks nobody.
 *
 * A custom domain a customer owns is never affected by this: it is their
 * registered domain, with their own limit.
 *
 * No Laravel dependencies — unit-testable.
 */
final class SharedZones
{
    /**
     * Registered domains the whole fleet issues under.
     *
     * @var list<string>
     */
    public const ZONES = [
        'sslip.io',
        'nip.io',
        'panelalpha.direct',
        'panelalpha.online',
    ];

    /**
     * Names the public DNS does not resolve and no public authority issues
     * for: RFC 2606 and RFC 6761 special-use, `.local` for mDNS, `.internal`
     * for private networks.
     *
     * Kept apart from {@see ZONES} on purpose. A shared zone is a real name
     * with a real certificate and a shared bill; these cannot hold a
     * certificate at all. They shared a list once, which only worked while
     * both meant "refuse".
     *
     * @var list<string>
     */
    public const RESERVED_TLDS = [
        'local',
        'localhost',
        'test',
        'invalid',
        'example',
        'internal',
        'onion',
        'home.arpa',
    ];

    /**
     * Whether a name sits under a wildcard zone the whole fleet issues under.
     * Reported, not enforced.
     */
    public static function covers(string $domain): bool
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));
        if ($domain === '') {
            return true;
        }

        foreach (self::ZONES as $zone) {
            if ($domain === $zone || str_ends_with($domain, '.'.$zone)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A name that can hold a certificate at all: a dotted, public-looking
     * hostname. Bare hostnames and addresses are neither.
     */
    public static function isPubliclyIssuable(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        if ($domain === '' || ! str_contains($domain, '.')) {
            return false;
        }
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $domain = trim($domain, '.');
        foreach (self::RESERVED_TLDS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return false;
            }
        }

        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1;
    }

    /**
     * The reason no authority could issue for the name, or null when one
     * could. Not a policy check: a bare hostname, an address or a reserved
     * TLD fails validation every time, and ordering one spends a failed
     * authorization to learn what was already knowable.
     *
     * Being on a shared zone is *not* a reason. That is a cost, reported
     * elsewhere, and the operator's to weigh.
     */
    /**
     * @param  bool  $sharedZoneAllowed  whether the operator has said this engine
     *                                   may spend the fleet's shared allowance. Passed in rather than
     *                                   read, so this class stays a statement about names.
     */
    public static function ineligibleReason(string $domain, bool $sharedZoneAllowed = true): ?string
    {
        if (! self::isPubliclyIssuable($domain)) {
            return "{$domain} is not a public hostname a certificate authority will issue for";
        }

        if (! $sharedZoneAllowed && self::covers($domain)) {
            return "{$domain} is under ".self::zoneOf($domain).', a zone every engine in the '
                .'fleet issues under and shares one 50-certificate weekly limit on. Set '
                .'shared_zone_issuance to obtain one anyway, or give the project a domain you own';
        }

        return null;
    }

    /**
     * Which shared zone a name sits under, for a message that names it.
     */
    public static function zoneOf(string $domain): ?string
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        foreach (self::ZONES as $zone) {
            if ($domain === $zone || str_ends_with($domain, '.'.$zone)) {
                return $zone;
            }
        }

        return null;
    }
}
