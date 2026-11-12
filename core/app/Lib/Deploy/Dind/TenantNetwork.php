<?php

namespace App\Lib\Deploy\Dind;

use App\System\Privileged;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The Docker network DinD accounts run on, held from the host, where a tenant cannot undo it: no account reaches another
 * (enable_icc=false), and the firewall script allows only sites-db and the two registries. Laravel-free, like {@see BuildNetwork}.
 */
final class TenantNetwork
{
    public const NAME = 'pash-tenants';

    /** The network the account template used before; accounts not yet moved are still on it. */
    public const LEGACY_NAME = 'pash-default-network';

    /**
     * The projects the engine allowed to call its API on :2011, one name per
     * line; the firewall script reads this path.
     */
    public const API_ACCESS_FILE = '/opt/panelalpha/shared-hosting/config/tenant-api-access';

    /** What the firewall refuses an account: the script's REFUSED, which a test keeps this equal to. */
    public const REFUSED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** sites-db's host number in the subnet; docker-compose.yml pins `<prefix>.0.2`. */
    private const SITES_DB_HOST = 2;

    /**
     * Creates the network when it is missing and applies its firewall, from the host namespace. Idempotent; run before
     * every account start, because a reboot drops the rules.
     *
     * @return list<string>
     */
    public static function firewallArgv(): array
    {
        return Privileged::host()->applyTenantNetworkFirewall();
    }

    /** Whether an account's connection to this IPv4 address is refused by the firewall. */
    public static function refuses(string $ipv4): bool
    {
        return filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && IpUtils::checkIp($ipv4, self::REFUSED_RANGES);
    }

    /** @return list<string> */
    public static function accountNetworksArgv(string $account): array
    {
        return Privileged::docker()->containerNetworks($account);
    }

    /**
     * An account's compose file with its network renamed to this one, and nothing else changed. Every account template,
     * old ones included, names the network once, as the external default.
     */
    public static function composeOnTenantNetwork(string $yaml): string
    {
        return (string) preg_replace(
            '/^(\s*name:\s*)'.preg_quote(self::LEGACY_NAME, '/').'\s*$/m',
            '${1}'.self::NAME,
            $yaml
        );
    }

    /**
     * sites-db's address as seen from an account, or null when the account is
     * not on this network (not moved yet, or not running).
     *
     * @param  string  $networksJson  output of {@see accountNetworksArgv()}
     */
    public static function sitesDbAddressFor(string $networksJson): ?string
    {
        $networks = json_decode(trim($networksJson), true);
        $endpoint = is_array($networks) ? ($networks[self::NAME] ?? null) : null;
        if (! is_array($endpoint)) {
            return null;
        }

        $ip = $endpoint['IPAddress'] ?? null;
        $bits = $endpoint['IPPrefixLen'] ?? null;
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_int($bits) || $bits < 1 || $bits > 30) {
            return null;
        }

        $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

        return long2ip((ip2long($ip) & $mask) + self::SITES_DB_HOST);
    }
}
