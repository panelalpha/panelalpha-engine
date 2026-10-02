<?php

namespace App\Lib\Deploy\Dind;

/**
 * The Docker network host build containers run on (engine#246).
 *
 * A host build runs the customer's install and build scripts on the *host*
 * daemon. On the default bridge that container could reach the engine API on
 * the bridge gateway and the public address, the host's private network and
 * the cloud metadata address. This network is a plain bridge with a fixed
 * interface name, and
 * scripts/build-network-firewall.sh lets it reach the internet and nothing
 * else. Builds need registries; they do not need the engine.
 *
 * Laravel-free and argv only, like {@see DindHostBuilder}.
 */
final class BuildNetwork
{
    public const DEFAULT_NAME = 'panelalpha-build';

    /** Fixed so the firewall matches it by name; `br-` so engine#241's DNAT skips it like any bridge. */
    public const BRIDGE = 'br-pa-build';

    public const FIREWALL_SCRIPT = '/opt/panelalpha/shared-hosting/scripts/build-network-firewall.sh';

    /**
     * The configured name, or null for "no dedicated network" -- an empty
     * DEPLOY_BUILD_NETWORK, which is the opt-out. A name Docker would refuse
     * falls back to the default rather than failing every build.
     */
    public static function resolve(?string $configured): ?string
    {
        if ($configured === null) {
            return self::DEFAULT_NAME;
        }
        $configured = trim($configured);
        if ($configured === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $configured) === 1
            ? $configured
            : self::DEFAULT_NAME;
    }

    /** @return list<string> */
    public static function inspectArgv(string $name): array
    {
        return ['sudo', 'docker', 'network', 'inspect', '--format', '{{.Id}}', $name];
    }

    /** @return list<string> */
    public static function createArgv(string $name): array
    {
        return [
            'sudo', 'docker', 'network', 'create',
            '--driver', 'bridge',
            '--opt', 'com.docker.network.bridge.name=' . self::BRIDGE,
            // Not enable_icc=false: Docker would load br_netfilter for it, and
            // that sends every bridge on the host -- tenants' inner networks
            // too -- through iptables. Builds are short-lived and alone.
            '--label', 'com.panelalpha.role=build',
            $name,
        ];
    }

    /**
     * The firewall lives in the host's netfilter, so it is applied from the
     * host namespace. Idempotent; run before every build because a reboot
     * drops it.
     *
     * @return list<string>
     */
    public static function firewallArgv(string $name): array
    {
        return ['sudo', 'nsenter', '--target', '1', '--all', 'sh', self::FIREWALL_SCRIPT, $name];
    }
}
