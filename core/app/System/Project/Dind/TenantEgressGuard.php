<?php

namespace App\System\Project\Dind;

use App\System\ComposeProject;

/**
 * Egress rules inside a DinD account, for the code the tenant runs (engine#217).
 *
 * A second layer. The boundary is the host's firewall on pash-tenants
 * ({@see \App\Lib\Deploy\Dind\TenantNetwork}, engine#519): the tenant holds
 * the inner Docker socket, and a privileged `--net=host` container of their
 * own edits these rules. Accounts not yet moved off pash-default-network,
 * which they share with core, SFTP, FTP and phpMyAdmin, have only this one.
 * Its apps' traffic is forwarded through the account's namespace (inner
 * DOCKER-USER, FORWARD) and its own processes, tenant crontabs included,
 * leave through OUTPUT; ServiceHardener keeps privileged, cap_add and
 * network_mode: host out of the compose the engine runs.
 *
 * On the account's own network it lets through only the shared MySQL (3306)
 * and the two image registries (5000). On any address of the host itself only
 * mail (25, msmtp's host.docker.internal) and the sites (80/443, cloudflared
 * and hairpin requests to the tenant's own domains). Link-local, which is the
 * cloud metadata address, is refused. Everything else -- the internet, other
 * private networks -- is left as it was.
 *
 * Rendered into the account's entrypoint.d, so entrypoint.sh runs it at boot,
 * and the account service {@see SERVICE} re-runs it: it has to follow the inner
 * daemon's DOCKER-USER chain once that exists and the registry and MySQL
 * addresses when those containers move.
 */
final class TenantEgressGuard
{
    public const FILE = 'egress-guard.sh';

    /** The account service that keeps {@see FILE} applied. */
    public const SERVICE = 'egress-guard';

    /**
     * That service's loop: every second until the inner daemon's DOCKER-USER is
     * hooked, then every 15s. The account's service manager runs it.
     */
    public const LOOP = 'while :; do [ -f /entrypoint.d/egress-guard.sh ] && sh /entrypoint.d/egress-guard.sh; '
        . 'if iptables -C DOCKER-USER -j PA-TENANT-EGRESS 2>/dev/null; then sleep 15; else sleep 1; fi; done';

    /** Names the account resolves through Docker's DNS on pash-default-network. */
    public const DATABASE_NAMES = ['database-users.shared-hosting.palocal', ComposeProject::NAME . '-sites-db-1'];
    public const REGISTRY_NAMES = ['panelalpha-cache-registry', 'panelalpha-registry-proxy'];

    /** Ports an account may use on the host: mail and the sites. */
    public const HOST_PORTS = '25 80 443';

    /**
     * On unless DIND_EGRESS_GUARD=false. Outside a booted application (unit
     * tests) it is on, like the production default.
     */
    public static function enabled(): bool
    {
        if (!function_exists('config')) {
            return true;
        }
        try {
            $value = config('env.DIND_EGRESS_GUARD', true);
        } catch (\Throwable) {
            return true;
        }

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * @param list<string> $hostIps every IPv4 address of the host (bridges,
     *        public, NAT); the default gateway and host.docker.internal are
     *        added in the account itself
     */
    public static function script(array $hostIps): string
    {
        $hostIps = array_values(array_filter(
            array_unique($hostIps),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ));

        $vars = [
            '__HOST_IPS__' => implode(' ', $hostIps),
            '__DB_NAMES__' => implode(' ', self::DATABASE_NAMES),
            '__REGISTRY_NAMES__' => implode(' ', self::REGISTRY_NAMES),
            '__HOST_PORTS__' => self::HOST_PORTS,
        ];

        return strtr(self::TEMPLATE, $vars);
    }

    private const TEMPLATE = <<<'SH'
#!/bin/sh
# engine#217: what the tenant's code may reach on the account's network and on
# the host. Rendered by the engine (TenantEgressGuard); do not edit here.
# Always exits 0 -- entrypoint.sh runs this at boot under set -e.

CHAIN=PA-TENANT-EGRESS
HOST_IPS="__HOST_IPS__"
DB_NAMES="__DB_NAMES__"
REGISTRY_NAMES="__REGISTRY_NAMES__"
HOST_PORTS="__HOST_PORTS__"
ROUTES="${PA_ROUTE_FILE:-/proc/net/route}"
STATE="${PA_GUARD_STATE:-/run/pa-egress-guard.state}"

hex2ip() {
    h=$1
    printf '%d.%d.%d.%d' "0x$(echo "$h" | cut -c7-8)" "0x$(echo "$h" | cut -c5-6)" \
        "0x$(echo "$h" | cut -c3-4)" "0x$(echo "$h" | cut -c1-2)"
}

# Every address a name resolves to now, or what it resolved to last time:
# a DNS hiccup must not cut an app off its database.
resolve() {
    key=$1; shift
    ips=$(for n in "$@"; do getent ahostsv4 "$n" 2>/dev/null | awk '{print $1}'; done | sort -u | tr '\n' ' ')
    if [ -z "$ips" ] && [ -f "$STATE" ]; then
        ips=$(sed -n "s/^$key=//p" "$STATE")
    fi
    echo "$ips"
}

guard() {
    command -v iptables >/dev/null 2>&1 || return 0
    dev=$(awk '$2 == "00000000" { print $1; exit }' "$ROUTES")
    gwhex=$(awk '$2 == "00000000" { print $3; exit }' "$ROUTES")
    nethex=$(awk -v d="$dev" '$1 == d && $2 != "00000000" && $3 == "00000000" { print $2" "$8; exit }' "$ROUTES")
    [ -n "$dev" ] && [ -n "$gwhex" ] && [ -n "$nethex" ] || return 0
    gw=$(hex2ip "$gwhex")
    net="$(hex2ip "${nethex% *}")/$(hex2ip "${nethex#* }")"
    hostdocker=$(getent ahostsv4 host.docker.internal 2>/dev/null | awk '{print $1; exit}')
    db=$(resolve db $DB_NAMES)
    reg=$(resolve reg $REGISTRY_NAMES)
    host=$(for ip in $gw $hostdocker $HOST_IPS; do echo "$ip"; done | sort -u | tr '\n' ' ')

    want="net=$net
db=$db
reg=$reg
host=$host"
    if [ -f "$STATE" ] && [ "$(cat "$STATE")" = "$want" ] && iptables -n -L "$CHAIN" >/dev/null 2>&1; then
        :
    else
        iptables -N "$CHAIN" 2>/dev/null || iptables -F "$CHAIN"
        iptables -A "$CHAIN" -o lo -j RETURN
        iptables -A "$CHAIN" -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
        for ip in $db; do iptables -A "$CHAIN" -d "$ip" -p tcp --dport 3306 -j RETURN; done
        for ip in $reg; do iptables -A "$CHAIN" -d "$ip" -p tcp --dport 5000 -j RETURN; done
        for ip in $host; do
            # One rule per port, not multiport: a sysbox account cannot load
            # a module the host has not.
            for port in $HOST_PORTS; do iptables -A "$CHAIN" -d "$ip" -p tcp --dport "$port" -j RETURN; done
            iptables -A "$CHAIN" -d "$ip" -j DROP
        done
        iptables -A "$CHAIN" -d "$net" -j DROP
        # Link-local is the metadata address; on some clouds (GCP) it is also
        # the resolver Docker's DNS forwards to from this namespace.
        iptables -A "$CHAIN" -d 169.254.0.0/16 -p udp --dport 53 -j RETURN
        iptables -A "$CHAIN" -d 169.254.0.0/16 -p tcp --dport 53 -j RETURN
        iptables -A "$CHAIN" -d 169.254.0.0/16 -j DROP
        echo "$want" >"$STATE"
        echo "[egress-guard] account network $net: MySQL ${db:-none}, registries ${reg:-none}, host ${host}on ports $HOST_PORTS only"
    fi

    # OUTPUT for the account's own processes; DOCKER-USER, which the inner
    # daemon evaluates first, for its containers once it exists, FORWARD
    # until then.
    for parent in OUTPUT FORWARD DOCKER-USER; do
        iptables -n -L "$parent" >/dev/null 2>&1 || continue
        iptables -C "$parent" -j "$CHAIN" 2>/dev/null || iptables -I "$parent" -j "$CHAIN"
    done
}

guard || true
exit 0
SH;
}
