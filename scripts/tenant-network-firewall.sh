#!/bin/sh
# The network hosting accounts sit on, and what they may reach.
#
# Accounts used to share pash-default-network with core, SFTP and phpMyAdmin,
# and the only filter was inside each account, where the tenant can remove it
# with a privileged container of their own. Here they get their own bridge, and
# the policy lives in the host's netfilter, out of the tenant's reach:
#   - account to account: dropped (enable_icc=false, and again below),
#   - to the shared services on this bridge: sites-db 3306 and the image
#     registries 5000 only, at addresses pinned in docker-compose.yml,
#   - the engine's own API on :2011, for the projects the engine allowed,
#     named in config/tenant-api-access, and for no one else,
#   - anything else private, link-local or reserved: refused, which covers
#     core, SFTP, the other networks and published ports DNAT turns into them,
#   - the host itself: mail (25) and the sites (80/443) only,
#   - the internet: open, masqueraded, within the default outbound limits of
#     tenant-egress-limits.sh (no SMTP, no floods, no scans).
# Nothing may open a connection into the network from outside it; the proxy
# reaches accounts from the host itself, which is not forwarded traffic.
#
# enable_icc=false makes Docker load br_netfilter, without which traffic
# between two ports of one bridge never reaches iptables at all.
#
# Usage: tenant-network-firewall.sh [--create [--restart-docker]] [--ports-on-stdin] [--without-limits]
#        tenant-network-firewall.sh --prefix
# Idempotent. Run at root broker start (a reboot empties iptables) and by the engine
# before an account starts. ufw reloads only its own chains, so it leaves these.
#
# --ports-on-stdin rebinds the ports from the broker's `subnet`, `member` and `port` lines; without
# it the binding stays and only the rules around it are replaced, so unbound ports stay closed.
# Its `source` lines name the build networks' bridges the outbound limits also cover.
# --without-limits leaves the outbound limits out; the root broker passes it when
# tenant-egress-limits.sh is missing or not root's alone.
#
# --prefix prints the prefix --create would pick, from `network <cidr>` lines on stdin.
#
# --create makes the network when it is missing, on the first /16 of
# 10.200-10.219 that nothing on the host uses (routes, addresses, Docker
# networks), and records it as TENANT_NETWORK_PREFIX in .env, which
# docker-compose.yml reads. A prefix already in .env is used as it is, and
# refused if it overlaps. --restart-docker is for the installers only: it
# restarts Docker once when a firewall flush (moving a host off CSF) left it
# unable to create networks.
# The root broker runs this script too, and restarting Docker there would stop it.
#
# panelalpha-tenant-guard (--install-units) runs --boot before every Docker start, all ports unbound;
# the root broker's tenant-network service then binds them.

# iptables-legacy gives up at once ("Another app is currently holding the
# xtables lock") while Docker, ufw or fail2ban is changing rules; -w waits for
# it, for at most 30 s. iptables-nft has no lock and ignores -w.
iptables() { command iptables -w 30 "$@"; }

CREATE=0
RESTART=0
BOOT=0
UNITS=0
PORTS_ON_STDIN=0
PREFIX=0
LIMITS=1
for arg in "$@"; do
    case "$arg" in
    --create) CREATE=1 ;;
    --restart-docker) RESTART=1 ;;
    --boot) BOOT=1 ;;
    --install-units) UNITS=1 ;;
    --ports-on-stdin) PORTS_ON_STDIN=1 ;;
    --prefix) PREFIX=1 ;;
    --without-limits) LIMITS=0 ;;
    esac
done

NET=pash-tenants
BRIDGE=br-pa-tenants
# What the root broker writes with --ports-on-stdin or --prefix; empty without them.
network_lines=
{ [ "$PORTS_ON_STDIN" = 1 ] || [ "$PREFIX" = 1 ]; } && network_lines=$(cat)
ENV_FILE="${PA_ENV_FILE:-/opt/panelalpha/shared-hosting/.env}"
# The engine writes the projects it allowed to call its API, one name per
# line; a missing or empty file admits none.
API_ACCESS_FILE="${PA_API_ACCESS_FILE:-/opt/panelalpha/shared-hosting/config/tenant-api-access}"
REFUSED="0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.168.0.0/16 198.18.0.0/15 224.0.0.0/4 240.0.0.0/4"

# The first two octets; docker-compose.yml derives the pinned addresses from it.
configured=$(sed -n 's/^TENANT_NETWORK_PREFIX=//p' "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d "\"' ")

# Every IPv4 network the host already uses: routes, addresses, Docker networks.
used_networks() {
    ip -4 route show 2>/dev/null |
        awk '$1 ~ /^(blackhole|unreachable|prohibit|throw)$/ { print $2; next } { print $1 }'
    ip -4 -o addr show 2>/dev/null | awk '{ print $4 }'
    if [ "$PREFIX" = 1 ]; then
        printf '%s\n' "$network_lines" | awk '$1 == "network" { print $2 }'
        return
    fi
    docker network ls -q 2>/dev/null |
        xargs -r docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null | tr ' ' '\n'
}

# Exit 0 when the network $1 overlaps any network read from stdin.
overlaps() {
    awk -v c="$1" '
        function num(ip,   o) { split(ip, o, "."); return ((o[1] * 256 + o[2]) * 256 + o[3]) * 256 + o[4] }
        function size(net,   p) { split(net, p, "/"); return 2 ^ (32 - (p[2] == "" ? 32 : p[2])) }
        function lo(net,   p) { split(net, p, "/"); return num(p[1]) - num(p[1]) % size(net) }
        function hi(net) { return lo(net) + size(net) - 1 }
        $1 ~ /^[0-9]+(\.[0-9]+)+(\/[0-9]+)?$/ && $1 !~ /\/0$/ {
            if (lo($1) <= hi(c) && lo(c) <= hi($1)) hit = 1
        }
        END { exit hit ? 0 : 1 }'
}

choose_prefix() {
    used=$(used_networks)
    if [ -n "$configured" ]; then
        if ! echo "$configured" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}$'; then
            echo "tenant-network-firewall: TENANT_NETWORK_PREFIX=$configured is not two octets" >&2
            return 1
        fi
        if echo "$used" | overlaps "$configured.0.0/16"; then
            echo "tenant-network-firewall: TENANT_NETWORK_PREFIX=$configured overlaps a network this host already uses; pick another in $ENV_FILE" >&2
            return 1
        fi
        echo "$configured"
        return 0
    fi
    for b in $(seq 200 219); do
        echo "$used" | overlaps "10.$b.0.0/16" || { echo "10.$b"; return 0; }
    done
    echo "tenant-network-firewall: no free /16 in 10.200-10.219 for $NET; set TENANT_NETWORK_PREFIX in $ENV_FILE" >&2
    return 1
}

# Writes the prefix to .env, so docker-compose.yml pins the same addresses.
record_prefix() {
    [ "$configured" = "$1" ] && return 0
    if [ -f "$ENV_FILE" ] && sed -i '/^TENANT_NETWORK_PREFIX=/d' "$ENV_FILE"; then
        [ -z "$(tail -c 1 "$ENV_FILE")" ] || echo >>"$ENV_FILE"
        echo "TENANT_NETWORK_PREFIX=$1" >>"$ENV_FILE" && configured=$1 && return 0
    fi
    [ "$1" = 10.200 ] && return 0 # docker-compose.yml's own default
    echo "tenant-network-firewall: could not record TENANT_NETWORK_PREFIX=$1 in $ENV_FILE" >&2
    return 1
}

# Accounts get addresses from the upper half only; the lower one is for the
# pinned services, so an account started while sites-db is down can never
# take its address.
create_network() {
    docker network create --driver bridge \
        --subnet "$1.0.0/16" --gateway "$1.0.1" --ip-range "$1.128.0/17" \
        --opt com.docker.network.bridge.name="$BRIDGE" \
        --opt com.docker.network.bridge.enable_icc=false \
        --opt com.docker.network.driver.mtu="${DOCKER_NETWORK_MTU:-1500}" \
        --label com.panelalpha.role=tenants "$NET" 2>&1 >/dev/null
}

install_units() {
    local dir="${PA_SYSTEMD_DIR:-/etc/systemd/system}" self
    self="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
    cat >"$dir/panelalpha-tenant-guard.service" <<EOF
# Written by the PanelAlpha engine (scripts/tenant-network-firewall.sh).
[Unit]
Description=PanelAlpha: hosting accounts' network closed until its ports are bound
After=local-fs.target ufw.service
Before=docker.service
PartOf=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh $self --boot

[Install]
WantedBy=docker.service multi-user.target
EOF
    remove_bind_unit "$dir"
    systemctl daemon-reload &&
        systemctl enable panelalpha-tenant-guard.service >/dev/null 2>&1 ||
        { echo "tenant-network-firewall: could not enable the boot unit" >&2; return 1; }
    echo "tenant-network-firewall: boot unit installed"
}

# panelalpha-tenant-bind runs this without the broker's lines, dropping the engine port's rule.
remove_bind_unit() {
    [ -e "$1/panelalpha-tenant-bind.service" ] || return 0
    systemctl disable panelalpha-tenant-bind.service >/dev/null 2>&1 || true
    rm -f "$1/panelalpha-tenant-bind.service"
    echo "tenant-network-firewall: panelalpha-tenant-bind.service removed"
}
if [ "$UNITS" = 1 ]; then
    install_units || exit 1
    exit 0
fi

if [ "$PREFIX" = 1 ]; then
    prefix=$(choose_prefix) || exit 1
    record_prefix "$prefix" || exit 1
    echo "$prefix"
    exit 0
fi

if [ "$BOOT" = 1 ]; then
    # Before Docker: no network to read, so the prefix .env records (written
    # whenever the network exists) or docker-compose.yml's default.
    prefix=${configured:-10.200}
    if ! echo "$prefix" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}$'; then
        echo "tenant-network-firewall: TENANT_NETWORK_PREFIX=$prefix is not two octets" >&2
        exit 1
    fi
    SUBNET="$prefix.0.0/16"
    # Docker keeps a DOCKER-USER it finds, and looks there before its own rules.
    iptables -N DOCKER-USER 2>/dev/null || true
elif [ "$CREATE" = 1 ] && ! docker network inspect "$NET" >/dev/null 2>&1; then
    prefix=$(choose_prefix) || exit 1
    record_prefix "$prefix" || exit 1
    if ! out=$(create_network "$prefix"); then
        echo "$out" >&2
        if [ "$RESTART" != 1 ] || ! printf '%s' "$out" | grep -qiE 'iptables|no chain'; then
            exit 1
        fi
        echo "tenant-network-firewall: Docker's iptables chains are missing (a firewall reload flushes them); restarting Docker and retrying" >&2
        systemctl reset-failed docker.service 2>/dev/null || true
        service docker restart || systemctl restart docker || exit 1
        for _ in $(seq 1 30); do
            docker info >/dev/null 2>&1 && break
            sleep 1
        done
        out=$(create_network "$prefix") || { echo "$out" >&2; exit 1; }
    fi
    echo "tenant-network-firewall: created $NET on $prefix.0.0/16"
fi
if [ "$BOOT" != 1 ]; then
    # The addresses come from the network that exists, not from .env: a prefix
    # edited after the network was made would otherwise allow addresses sites-db
    # does not have, and every account would lose its database without a word.
    SUBNET=$(printf '%s\n' "$network_lines" | awk '$1 == "subnet" { print $2; exit }')
    if [ -z "$SUBNET" ]; then
        docker network inspect "$NET" >/dev/null 2>&1 || {
            echo "tenant-network-firewall: no docker network $NET" >&2
            exit 1
        }
        SUBNET=$(docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' "$NET" | awk '{print $1}')
    fi
    actual=$(echo "$SUBNET" | cut -d. -f1-2)
    case "$SUBNET" in
    *.0.0/16) ;;
    *)
        echo "tenant-network-firewall: $NET has subnet '$SUBNET', expected <prefix>.0.0/16" >&2
        exit 1
        ;;
    esac
    if [ -z "$configured" ]; then
        record_prefix "$actual" || true
    elif [ "$configured" != "$actual" ]; then
        echo "tenant-network-firewall: TENANT_NETWORK_PREFIX is $configured but $NET is $SUBNET; using $actual (docker-compose.yml will refuse $configured)" >&2
    fi
    prefix=$actual
fi
SITES_DB="$prefix.0.2"
CACHE_REGISTRY="$prefix.0.3"
REGISTRY_PROXY="$prefix.0.4"
GHCR_PROXY="$prefix.0.6"

# The engine's API port, for the accounts the engine allowed. Judged before
# the private ranges are refused, and by the address Docker gave the container
# on this bridge: an account cannot announce another's. None with --boot.
api_access_rules() {
    [ "$BOOT" = 1 ] && return 0
    [ -f "$API_ACCESS_FILE" ] || return 0
    allowed=$(sed -e 's/#.*//' -e 's/[[:space:]]//g' "$API_ACCESS_FILE" 2>/dev/null | grep -v '^$')
    [ -n "$allowed" ] || return 0
    printf '%s\n' "$network_lines" | awk '$1 == "member" { print $2, $3 }' | while read -r name ip; do
        echo "$allowed" | grep -qxF "$name" || continue
        echo "-A PA-TENANT-NET -i $BRIDGE -s $ip/32 -p tcp -m conntrack --ctstate DNAT --ctorigdstport 2011 -j ACCEPT"
    done
}

exec 9>"${PA_TENANT_LOCK:-/run/pa-tenant-network.lock}"
flock 9

# Each chain is emptied and refilled in one iptables-restore transaction, so
# there is no moment where it is empty and an account's traffic falls through.
{
    echo '*filter'
    echo ':PA-TENANT-NET - [0:0]'
    echo ':PA-TENANT-INPUT - [0:0]'
    echo '-A PA-TENANT-NET -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT'
    echo "-A PA-TENANT-NET ! -i $BRIDGE -j DROP"
    echo "-A PA-TENANT-NET -o $BRIDGE -d $SITES_DB/32 -p tcp --dport 3306 -j ACCEPT"
    echo "-A PA-TENANT-NET -o $BRIDGE -d $CACHE_REGISTRY/32 -p tcp --dport 5000 -j ACCEPT"
    echo "-A PA-TENANT-NET -o $BRIDGE -d $REGISTRY_PROXY/32 -p tcp --dport 5000 -j ACCEPT"
    echo "-A PA-TENANT-NET -o $BRIDGE -d $GHCR_PROXY/32 -p tcp --dport 5000 -j ACCEPT"
    echo "-A PA-TENANT-NET -o $BRIDGE -j DROP"
    api_access_rules
    for cidr in $REFUSED; do
        echo "-A PA-TENANT-NET -d $cidr -j REJECT --reject-with icmp-net-prohibited"
    done
    echo '-A PA-TENANT-NET -j ACCEPT'
    echo '-A PA-TENANT-INPUT -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT'
    echo '-A PA-TENANT-INPUT -p tcp -m multiport --dports 25,80,443 -j ACCEPT'
    echo '-A PA-TENANT-INPUT -j REJECT --reject-with icmp-host-prohibited'
    echo 'COMMIT'
} | iptables-restore -w 30 --noflush || exit 1

# DOCKER-USER is where Docker looks first and it survives a daemon restart.
# FORWARD and INPUT as well, for a host where a flush removed Docker's chains.
for parent in DOCKER-USER FORWARD; do
    iptables -n -L "$parent" >/dev/null 2>&1 || continue
    for dir in -i -o; do
        iptables -C "$parent" "$dir" "$BRIDGE" -j PA-TENANT-NET 2>/dev/null ||
            iptables -I "$parent" "$dir" "$BRIDGE" -j PA-TENANT-NET
    done
done
iptables -C INPUT -i "$BRIDGE" -j PA-TENANT-INPUT 2>/dev/null ||
    iptables -I INPUT -i "$BRIDGE" -j PA-TENANT-INPUT
# The proxy runs on the host and reaches an app on whatever port it listens on.
# Allowed past the host firewall's outgoing policy, should an operator make ufw
# deny outgoing by default.
iptables -C OUTPUT -o "$BRIDGE" -j ACCEPT 2>/dev/null ||
    iptables -I OUTPUT -o "$BRIDGE" -j ACCEPT
iptables -t nat -C POSTROUTING -s "$SUBNET" ! -o "$BRIDGE" -j MASQUERADE 2>/dev/null ||
    iptables -t nat -A POSTROUTING -s "$SUBNET" ! -o "$BRIDGE" -j MASQUERADE

# Each port of the bridge speaks only as the container Docker put
# on it. The rules above judge IP headers, and an account holding its own
# network namespace (any --privileged container of its own) can announce
# another member's address: ARP for sites-db's took other accounts' MySQL
# connections, ARP for an account's moved the host's neighbour entry and with
# it the proxy's traffic for that site. ARP never reaches iptables at all.
#
# An nft table of the bridge family sees every frame entering from a port,
# before it is switched, and accepts ARP and IPv4 only when the port, the
# frame's MAC and the sender address are the triple Docker attached; anything
# else from a port, IPv6 included, is dropped. Frames the host sends do not
# pass this hook. A container not bound yet is cut off until the broker binds it.
if command -v nft >/dev/null 2>&1; then
    # Only the broker's lines replace the sets; any other run flushes the rules and keeps them.
    bindings=
    if [ "$PORTS_ON_STDIN" = 1 ] && [ "$BOOT" != 1 ]; then
        bindings=$(printf '%s\n' "$network_lines" | awk '$1 == "port" { print $2, $3, $4 }')
        replace='delete table bridge pa_tenants'
        bound="$(echo "$bindings" | grep -c .) port(s) bound"
    else
        replace='flush table bridge pa_tenants'
        bound='its port bindings kept'
    fi
    arp=$(echo "$bindings" | awk 'NF == 3 { printf "%s\"%s\" . %s . %s . %s", s, $1, $2, $2, $3; s = ", " }')
    ipv4=$(echo "$bindings" | awk 'NF == 3 { printf "%s\"%s\" . %s . %s", s, $1, $2, $3; s = ", " }')
    # Created and refilled in one transaction: never absent, never half-filled.
    {
        echo 'add table bridge pa_tenants'
        echo "$replace"
        echo 'table bridge pa_tenants {'
        echo '    set arp_ok {'
        echo '        type ifname . ether_addr . ether_addr . ipv4_addr'
        [ -n "$arp" ] && echo "        elements = { $arp }"
        echo '    }'
        echo '    set ip_ok {'
        echo '        type ifname . ether_addr . ipv4_addr'
        [ -n "$ipv4" ] && echo "        elements = { $ipv4 }"
        echo '    }'
        echo '    chain ports {'
        echo '        type filter hook prerouting priority -300; policy accept;'
        echo "        meta ibrname != \"$BRIDGE\" accept"
        echo '        ether type arp iifname . ether saddr . arp saddr ether . arp saddr ip @arp_ok accept'
        echo '        ether type ip iifname . ether saddr . ip saddr @ip_ok accept'
        echo '        counter drop'
        echo '    }'
        echo '}'
    } | nft -f - || exit 1
else
    echo "tenant-network-firewall: nft is missing; ports are not bound to their addresses (install nftables)" >&2
    bound='none port(s) bound'
fi

# The limits on new outbound connections (tenant-egress-limits.sh), on this
# bridge and every build network's the broker named. Kept apart from the
# isolation above: a kernel that refuses them, or a missing script, leaves the
# isolation standing, and the broker reports the limits missing.
limits="$(dirname "$0")/tenant-egress-limits.sh"
sources=$(printf '%s\n' "$network_lines" | awk '$1 == "source" && $2 ~ /^[a-zA-Z0-9_.-]+$/ { printf " --source %s", $2 }')
if [ "$LIMITS" = 0 ]; then
    :
elif [ -f "$limits" ]; then
    # shellcheck disable=SC2086
    sh "$limits" $sources >/dev/null || true
else
    echo "tenant-network-firewall: $limits is missing; the outbound limits are not applied" >&2
fi

if [ "$BOOT" = 1 ]; then
    echo "tenant-network-firewall: $BRIDGE ($SUBNET) closed until its ports are bound"
    exit 0
fi
echo "tenant-network-firewall: $NET ($BRIDGE, $SUBNET) applied, $bound"
