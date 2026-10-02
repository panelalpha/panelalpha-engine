<?php

namespace App\Lib\Deploy\Dind;

/**
 * The Docker network DinD accounts run on (engine#519).
 *
 * Accounts used to share pash-default-network with core, SFTP and phpMyAdmin,
 * filtered only from inside the account, where the tenant holds the inner
 * Docker socket and can undo it. On this network accounts cannot reach each
 * other (enable_icc=false) and scripts/tenant-network-firewall.sh holds them,
 * from the host, to sites-db and the two registries, which docker-compose.yml
 * attaches at fixed addresses.
 *
 * Laravel-free and argv only, like {@see BuildNetwork}.
 */
final class TenantNetwork
{
    public const NAME = 'pash-tenants';

    /** The network the account template used before; accounts not yet moved are still on it. */
    public const LEGACY_NAME = 'pash-default-network';

    public const FIREWALL_SCRIPT = '/opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh';

    /** sites-db's host number in the subnet; docker-compose.yml pins `<prefix>.0.2`. */
    private const SITES_DB_HOST = 2;

    /**
     * Creates the network when it is missing and applies its firewall, from
     * the host namespace. Idempotent; run before every account start, because
     * a reboot drops the rules.
     *
     * @return list<string>
     */
    public static function firewallArgv(): array
    {
        return ['sudo', 'nsenter', '--target', '1', '--all', 'sh', self::FIREWALL_SCRIPT, '--create'];
    }

    /** @return list<string> */
    public static function accountNetworksArgv(string $account): array
    {
        return ['sudo', 'docker', 'inspect', '--format', '{{json .NetworkSettings.Networks}}', $account];
    }

    /**
     * An account's compose file with its network renamed to this one, and
     * nothing else changed. Every account template, old ones included, names
     * the network once, as the external default.
     */
    public static function composeOnTenantNetwork(string $yaml): string
    {
        return (string) preg_replace(
            '/^(\s*name:\s*)' . preg_quote(self::LEGACY_NAME, '/') . '\s*$/m',
            '${1}' . self::NAME,
            $yaml
        );
    }

    /**
     * sites-db's address as seen from an account, or null when the account is
     * not on this network (not moved yet, or not running).
     *
     * @param string $networksJson output of {@see accountNetworksArgv()}
     */
    public static function sitesDbAddressFor(string $networksJson): ?string
    {
        $networks = json_decode(trim($networksJson), true);
        $endpoint = is_array($networks) ? ($networks[self::NAME] ?? null) : null;
        if (!is_array($endpoint)) {
            return null;
        }

        $ip = $endpoint['IPAddress'] ?? null;
        $bits = $endpoint['IPPrefixLen'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !is_int($bits) || $bits < 1 || $bits > 30) {
            return null;
        }

        $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

        return long2ip((ip2long($ip) & $mask) + self::SITES_DB_HOST);
    }
}
