<?php

namespace App\Lib\Deploy\Dind;

use App\System\Privileged;

/**
 * The Docker network host build containers run on: a plain bridge with a fixed interface name, which
 * scripts/build-network-firewall.sh lets reach the internet and nothing else, the engine included.
 */
final class BuildNetwork
{
    public const DEFAULT_NAME = 'panelalpha-build';

    /** Fixed so the firewall matches it by name; `br-` so the DNAT rules skip it like any bridge. */
    public const BRIDGE = 'br-pa-build';

    /**
     * The configured name, or null for an empty DEPLOY_BUILD_NETWORK, the opt-out. A name Docker would
     * refuse falls back to the default rather than failing every build.
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
        return Privileged::docker()->networkId($name);
    }

    /**
     * Not enable_icc=false: Docker would load br_netfilter for it, sending every bridge on the host
     * through iptables. Builds are short-lived and alone.
     *
     * @return list<string>
     */
    public static function createArgv(string $name): array
    {
        return Privileged::docker()->createBridgeNetwork($name, self::BRIDGE, 'build');
    }

    /**
     * The firewall lives in the host's netfilter, so it is applied from the host namespace; idempotent,
     * and run before every build because a reboot drops it.
     *
     * @return list<string>
     */
    public static function firewallArgv(string $name): array
    {
        return Privileged::host()->applyBuildNetworkFirewall($name);
    }
}
