<?php

namespace App\Lib\Ssl;

/**
 * The wildcard zones every engine shares. None can be on the Public Suffix List, so Let's Encrypt's 50-a-week
 * limit is fleet-wide and a project domain under one keeps the self-signed certificate. No Laravel dependencies.
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
     * Special-use names (RFC 2606, RFC 6761, `.local`, `.internal`) no public authority issues for. Kept apart
     * from {@see ZONES}: a shared zone holds a certificate at a shared cost, these cannot hold one at all.
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
     * Why no authority could issue for the name, or null; ordering one anyway only spends a failed authorization.
     * A shared zone is a cost for the operator to weigh, a reason only when $sharedZoneAllowed is false.
     *
     * @param  bool  $sharedZoneAllowed  whether the operator lets this engine spend the fleet's shared allowance
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
