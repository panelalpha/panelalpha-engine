#!/bin/sh
# Egress rules for the network host build containers run on (engine#246).
#
# A host build runs the customer's install and build scripts (npm postinstall,
# composer, cargo build.rs) in a container on the *host* daemon. On the default
# bridge that container reached the engine API on the bridge gateway and the
# public address, the host's private network and 169.254.169.254. Builds need
# the internet for their registries and nothing else, so on this network:
#   - anything addressed to the host itself is refused (INPUT),
#   - anything routed to a private, link-local or reserved range is refused,
#     which covers core, the shared MySQL and every other container, including
#     published ports that DNAT turns into a container address,
#   - everything else leaves, masqueraded.
# DNS is unaffected: Docker's embedded resolver forwards from the daemon.
#
# Usage: build-network-firewall.sh [network]   (default panelalpha-build)
# Idempotent. Run by the engine before every host build -- a reboot or CSF
# restart drops these rules -- and from csfpost.sh.

NET="${1:-panelalpha-build}"
CHAIN=PA-BUILD-EGRESS
REFUSED="0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.168.0.0/16 198.18.0.0/15 224.0.0.0/4 240.0.0.0/4"

bridge=$(docker network inspect -f '{{index .Options "com.docker.network.bridge.name"}}' "$NET" 2>/dev/null) || {
    echo "build-network-firewall: no docker network $NET" >&2
    exit 1
}
[ -n "$bridge" ] || bridge="br-$(docker network inspect -f '{{.Id}}' "$NET" | cut -c1-12)"
subnet=$(docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' "$NET" | awk '{print $1}')

# Two builds can start together; the chain is emptied and refilled below.
exec 9>/run/pa-build-network.lock
flock 9

set -e
iptables -N "$CHAIN" 2>/dev/null || iptables -F "$CHAIN"
for cidr in $REFUSED; do
    iptables -A "$CHAIN" -d "$cidr" -j REJECT --reject-with icmp-net-prohibited
done
# Build to build: only seen where br_netfilter is loaded, which this does not do.
iptables -A "$CHAIN" -o "$bridge" -j REJECT --reject-with icmp-net-prohibited
iptables -A "$CHAIN" -j ACCEPT

# DOCKER-USER is where Docker promises to look first, and it survives a daemon
# restart. FORWARD too, for a host where CSF removed Docker's chains.
for parent in DOCKER-USER FORWARD; do
    iptables -n -L "$parent" >/dev/null 2>&1 || continue
    iptables -C "$parent" -i "$bridge" -j "$CHAIN" 2>/dev/null ||
        iptables -I "$parent" -i "$bridge" -j "$CHAIN"
done
iptables -C FORWARD -o "$bridge" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null ||
    iptables -I FORWARD -o "$bridge" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -C INPUT -i "$bridge" -j REJECT --reject-with icmp-host-prohibited 2>/dev/null ||
    iptables -I INPUT -i "$bridge" -j REJECT --reject-with icmp-host-prohibited
if [ -n "$subnet" ]; then
    iptables -t nat -C POSTROUTING -s "$subnet" ! -o "$bridge" -j MASQUERADE 2>/dev/null ||
        iptables -t nat -A POSTROUTING -s "$subnet" ! -o "$bridge" -j MASQUERADE
fi

echo "build-network-firewall: $NET ($bridge${subnet:+, $subnet}) egress to the internet only"
