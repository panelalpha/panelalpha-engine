#!/bin/sh
# Puts back the DNAT for the engine API (:2011) that CSF removes (engine#241).
#
# CSF flushes the nat table on every start and `csf -r` -- the engine itself runs
# `csf -r` for every firewall change made through the API -- and Docker writes a
# container's DNAT only when that container starts. From then on :2011 is served
# by docker-proxy, which connects to core from the bridge gateway, so core sees
# one private address for every client: per-IP limits become one bucket and the
# access log names nobody.
#
# This re-creates that one DNAT and sends it through CSF's own LOCALINPUT chain
# (csf.allow, csf.deny, lfd bans), which is what filtered :2011 on INPUT before.
# Traffic from the container bridges and the host itself is left to docker-proxy.
#
# Usage: csf-publish-core.sh [core-ip]
#   run from csfpost.sh (no argument: the address is looked up) and from core's
#   entrypoint (its own address). Idempotent; a no-op when CSF is not running.

PORT=2011
CHAIN=PA-PUBLISH-CORE

# Only under CSF. Without it Docker's own DNAT is intact and already right.
iptables -n -L LOCALINPUT >/dev/null 2>&1 || exit 0

ip="${1:-}"
if [ -z "$ip" ]; then
    id=$(docker ps -q \
        --filter label=com.docker.compose.project=shared-hosting \
        --filter label=com.docker.compose.service=core 2>/dev/null | head -n 1)
    [ -n "$id" ] && ip=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "$id" 2>/dev/null | awk '{print $1}')
fi

iptables -t nat -N "$CHAIN" 2>/dev/null || iptables -t nat -F "$CHAIN"
iptables -N "$CHAIN" 2>/dev/null || iptables -F "$CHAIN"

# Core not running: leave the chains empty rather than pointing at a dead address.
if [ -z "$ip" ]; then
    echo "csf-publish-core: core is not running; :2011 stays on docker-proxy"
    exit 0
fi
bridge=$(ip -o route get "$ip" 2>/dev/null | sed -n 's/.* dev \([^ ]*\).*/\1/p')
if [ -z "$bridge" ]; then
    echo "csf-publish-core: no route to $ip; :2011 stays on docker-proxy" >&2
    exit 0
fi

iptables -t nat -A "$CHAIN" -i docker0 -j RETURN
iptables -t nat -A "$CHAIN" -i br-+ -j RETURN
iptables -t nat -A "$CHAIN" -p tcp --dport "$PORT" -j DNAT --to-destination "$ip:$PORT"
iptables -t nat -C PREROUTING -m addrtype --dst-type LOCAL -j "$CHAIN" 2>/dev/null ||
    iptables -t nat -I PREROUTING -m addrtype --dst-type LOCAL -j "$CHAIN"

iptables -A "$CHAIN" -d "$ip" -p tcp --dport "$PORT" -m conntrack --ctstate DNAT -j LOCALINPUT
iptables -A "$CHAIN" -d "$ip" -p tcp --dport "$PORT" -m conntrack --ctstate DNAT -j ACCEPT
iptables -C FORWARD -o "$bridge" -j "$CHAIN" 2>/dev/null ||
    iptables -I FORWARD -o "$bridge" -j "$CHAIN"

echo "csf-publish-core: :$PORT -> $ip ($bridge)"
