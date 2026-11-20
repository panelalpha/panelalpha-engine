#!/bin/bash
# Exercises tenant-egress-limits.sh: the transaction nft accepts, the settings,
# and the limits on real packets. A user and network namespace of its own
# stands in for the host: a bridge named like the accounts' network, one
# "account" namespace behind it, and a dummy uplink that swallows what leaves.
# Nothing reaches the machine running it.
#
#   bash tests/scripts/tenant-egress-limits.test.sh
#
# Needs nft and unprivileged user namespaces; skips (exit 0) without them.
set -uo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../scripts" && pwd)/tenant-egress-limits.sh"
. "$(dirname "${BASH_SOURCE[0]}")/test-network.sh"
W="$(mktemp -d)"
trap 'kill "${ACCOUNT_PID:-}" 2>/dev/null; rm -rf "$W"' EXIT
fails=0
pass() { echo "ok   $1"; }
fail() { echo "FAIL $1"; fails=$((fails + 1)); }
check() { if eval "$2"; then pass "$1"; else fail "$1"; fi; }

if ! command -v nft >/dev/null || ! unshare -rn true 2>/dev/null; then
    echo "skip: needs nft and unprivileged user namespaces"
    exit 0
fi

# --- the transaction and the settings -------------------------------------

env_file() { printf '%s\n' "$@" >"$W/env"; }
printed() { PA_ENV_FILE="$W/env" sh "$SCRIPT" --print "${@}" 2>"$W/stderr"; }

env_file
printed >"$W/default.nft"
check "the default transaction passes nft -c" "unshare -rn nft -c -f '$W/default.nft'"
check "SMTP is dropped by default" "grep -q 'new_conn tcp dport 25 jump smtp' '$W/default.nft'"
check "100 new connections a second, burst 1000" "grep -q 'limit rate over 100/second burst 1000 packets' '$W/default.nft'"
check "60 new destinations, then 20 a minute" "grep -q 'limit rate over 20/minute burst 60 packets' '$W/default.nft'"
check "a scan blocks for 30 minutes" "grep -q 'add @blocked4 { ip saddr timeout 1800s }' '$W/default.nft'"
check "IPv6 has the same limits" "grep -q 'update @newdst6 { ip6 saddr limit rate over 20/minute' '$W/default.nft'"
check "the web ports are counted apart" "grep -q 'new_conn tcp dport { 80, 443 } goto new_web' '$W/default.nft'"
check "600 new web destinations, then 200 a minute" \
    "grep -q 'new_web update @newdstweb4 { ip saddr limit rate over 200/minute burst 600 packets } jump scan' '$W/default.nft'"
check "with pairs seen of their own" "grep -q 'new_web add @seenweb4 { ip saddr . ip daddr }' '$W/default.nft' && ! grep -q 'new_web.*@seen4' '$W/default.nft'"
check "only new connections leaving the two networks are judged" \
    "grep -q 'forward iifname @sources oifname != @sources ct state new jump new_conn' '$W/default.nft'"

env_file EGRESS_BLOCK_SMTP=0 EGRESS_NEW_CONNECTIONS_PER_SECOND=0 EGRESS_NEW_DESTINATIONS_PER_MINUTE=0
printed >"$W/off.nft"
check "every limit off still passes nft -c" "unshare -rn nft -c -f '$W/off.nft'"
check "0 allows SMTP" "! grep -q 'dport 25' '$W/off.nft'"
check "0 turns the flood limit off" "! grep -q 'connrate4 {' '$W/off.nft' || ! grep -q 'update @connrate4' '$W/off.nft'"
check "0 turns the scan limit off" "! grep -q 'update @newdst4' '$W/off.nft'"
check "and leaves the web ports' own" "grep -q 'update @newdstweb4' '$W/off.nft' && grep -q 'saddr @blocked4' '$W/off.nft'"

env_file EGRESS_WEB_NEW_DESTINATIONS_BURST=0
printed >"$W/webless.nft"
check "the web limit off still passes nft -c" "unshare -rn nft -c -f '$W/webless.nft'"
check "0 turns the web scan limit off and the web ports are not counted with the rest" \
    "! grep -q 'update @newdstweb4' '$W/webless.nft' && grep -q 'new_conn tcp dport { 80, 443 } return' '$W/webless.nft' && grep -q 'update @newdst4' '$W/webless.nft'"
env_file EGRESS_WEB_NEW_DESTINATIONS_BURST=0 EGRESS_NEW_DESTINATIONS_PER_MINUTE=0
printed >"$W/noscan.nft"
check "both scan limits off judge no destination and block nothing" \
    "! grep -q 'dport { 80, 443 }' '$W/noscan.nft' && ! grep -q 'saddr @blocked4' '$W/noscan.nft' && unshare -rn nft -c -f '$W/noscan.nft'"

env_file EGRESS_SCAN_BLOCK_MINUTES=0 'EGRESS_NEW_DESTINATIONS_BURST="40"'
printed >"$W/noblock.nft"
check "a block of 0 minutes only drops what is over the limit" \
    "! grep -q 'add @blocked4' '$W/noblock.nft' && grep -q 'scan update @scan4 { ip saddr }' '$W/noblock.nft' && ! grep -q 'saddr @blocked4' '$W/noblock.nft'"
check "a quoted value is read" "grep -q 'burst 40 packets' '$W/noblock.nft'"

env_file EGRESS_NEW_DESTINATIONS_PER_MINUTE=lots
printed >"$W/bad.nft"
check "a value that is not a number falls back to its default" "grep -q 'over 20/minute' '$W/bad.nft'"
check "and says so" "grep -q 'EGRESS_NEW_DESTINATIONS_PER_MINUTE=lots is not a whole number; using 20' '$W/stderr'"

env_file EGRESS_SCAN_BLOCK_MINUTES=45 EGRESS_BLOCK_SMTP=x
check "--settings prints the values in force" "[ \"\$(PA_ENV_FILE='$W/env' sh '$SCRIPT' --settings 2>/dev/null | tr '\\n' ' ')\" = 'EGRESS_BLOCK_SMTP=1 EGRESS_NEW_CONNECTIONS_PER_SECOND=100 EGRESS_NEW_DESTINATIONS_PER_MINUTE=20 EGRESS_NEW_DESTINATIONS_BURST=60 EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE=200 EGRESS_WEB_NEW_DESTINATIONS_BURST=600 EGRESS_SCAN_BLOCK_MINUTES=45 ' ]"

env_file
printed --source br-custom >"$W/source.nft"
check "a build network's own bridge joins the sources" "grep -q '\"br-pa-tenants\", \"br-pa-build\", \"br-custom\"' '$W/source.nft'"
check "a bridge name with a quote in it is refused" "! PA_ENV_FILE='$W/env' sh '$SCRIPT' --print --source 'br\"x' >/dev/null 2>&1"

# --- packets ----------------------------------------------------------------
# Small limits so the run is short: 5 new destinations, then 2 a minute, and
# 10 then 4 on the web ports; 20 new connections a second; a 2-minute block.
env_file EGRESS_NEW_DESTINATIONS_BURST=5 EGRESS_NEW_DESTINATIONS_PER_MINUTE=2 \
    EGRESS_WEB_NEW_DESTINATIONS_BURST=10 EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE=4 \
    EGRESS_NEW_CONNECTIONS_PER_SECOND=20 EGRESS_SCAN_BLOCK_MINUTES=2

cat >"$W/connect.py" <<'PY'
import socket, sys
# connect.py <port> <address>...: one SYN to each, never waiting for an answer.
port = int(sys.argv[1])
for address in sys.argv[2:]:
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    s.setblocking(False)
    try:
        s.connect((address, port))
    except BlockingIOError:
        pass
    s.close()
PY

cat >"$W/host.sh" <<HOST
#!/bin/bash
set -u
W="$W"
SCRIPT="$SCRIPT"
TENANT_GATEWAY="$(tn_add "${TN_TENANT_NETWORK_CIDR%/*}" 1)"
TENANT="$TN_TENANT_ACCOUNT"
UPLINK="$(tn_host PUBLIC_3 1)"
UPLINK_ROUTER="$(tn_host PUBLIC_3 2)"
SMTP_SERVER="$(tn_host PUBLIC_2 7)"
DESTINATIONS="$(tn_host PUBLIC 10) $(tn_host PUBLIC 11) $(tn_host PUBLIC 12)"
SERVICE_GATEWAY="$(tn_host CONTAINER 1)"
SERVICE="$(tn_host CONTAINER 8)"
OUTSIDE="$(tn_host PUBLIC 99)"
FLOODED="$(tn_host PUBLIC 200)"
HOST
cat >>"$W/host.sh" <<'HOST'
export PA_ENV_FILE="$W/env"
echo 1 >/proc/sys/net/ipv4/ip_forward
ip link add br-pa-tenants type bridge && ip link set br-pa-tenants up
ip addr add "$TENANT_GATEWAY/16" dev br-pa-tenants
ip link add up0 type dummy && ip link set up0 up
ip addr add "$UPLINK/24" dev up0
ip route add default via "$UPLINK_ROUTER" dev up0 onlink
unshare -n sleep 600 &
ACCOUNT=$!
sleep 0.3
ip link add vh type veth peer name vc netns /proc/$ACCOUNT/ns/net
ip link set vh master br-pa-tenants && ip link set vh up
nsenter -t "$ACCOUNT" -n sh -c "ip link set lo up; ip link set vc up; ip addr add $TENANT/16 dev vc; ip route add default via $TENANT_GATEWAY"
in_account() { nsenter -t "$ACCOUNT" -n "$@"; }
elements() { nft -j list set inet pa_egress "$1" 2>/dev/null | python3 -c 'import json,sys; d=json.load(sys.stdin)["nftables"]; s=[x["set"] for x in d if "set" in x][0]; print(" ".join(sorted(str(e["elem"]["val"] if isinstance(e,dict) else e) for e in s.get("elem",[]))))'; }

sh "$SCRIPT" >/dev/null || { echo "apply failed"; exit 1; }
echo "apply: $(sh "$SCRIPT")"

in_account python3 "$W/connect.py" 25 "$SMTP_SERVER"
echo "smtp: $(elements smtp4)"

in_account python3 "$W/connect.py" 22 $DESTINATIONS
echo "three new destinations: blocked=[$(elements blocked4)]"

web=$(for i in $(seq 20 27); do printf '203.0.113.%s ' "$i"; done)
in_account python3 "$W/connect.py" 443 $web
echo "eight new web destinations: blocked=[$(elements blocked4)]"

in_account python3 "$W/connect.py" 22 $web
echo "scan: blocked=[$(elements blocked4)]"

sh "$SCRIPT" >/dev/null
echo "after re-apply: blocked=[$(elements blocked4)]"

ip link add svc0 type dummy && ip link set svc0 up
ip addr add "$SERVICE_GATEWAY/16" dev svc0
nft add table ip published
nft add chain ip published prerouting '{ type nat hook prerouting priority dstnat; }'
nft add rule ip published prerouting ip daddr "$UPLINK" tcp dport 2011 dnat to "$SERVICE"
nft add chain ip published forward '{ type filter hook forward priority filter; }'
nft add rule ip published forward ip daddr "$SERVICE" tcp dport 2011 counter
nft add rule ip published forward ip daddr "$OUTSIDE" counter
in_account python3 "$W/connect.py" 2011 "$UPLINK"
in_account python3 "$W/connect.py" 443 "$OUTSIDE"
echo "published port while blocked: $(nft list chain ip published forward | grep -o 'packets [0-9]*' | tr '\n' ' ')"

unblock() {
    nft delete element inet pa_egress blocked4 "{ $TENANT }"
    for name in newdst4 newdstweb4 seen4 seenweb4; do nft flush set inet pa_egress "$name"; done
}
unblock
addresses=$(for i in $(seq 100 140); do printf '203.0.113.%s ' "$i"; done)
in_account python3 "$W/connect.py" 80 $addresses
echo "web scan: blocked=[$(elements blocked4)]"

unblock
burst=$(for i in $(seq 1 400); do printf '%s ' "$FLOODED"; done)
in_account python3 "$W/connect.py" 8443 $burst
echo "flood: $(elements flood4) blocked=[$(elements blocked4)]"

nft delete table inet pa_egress
nft add table inet pa_egress
nft add set inet pa_egress newdst4 '{ type ipv4_addr; size 16; }'
echo "other shape: $(sh "$SCRIPT")"
nft add set inet pa_egress seen '{ type ipv4_addr . ipv4_addr; flags dynamic, timeout; timeout 10m; }'
nft add chain inet pa_egress tenant_new
echo "hand-made objects: $(sh "$SCRIPT") left=[$(nft list table inet pa_egress | awk '$2 == "seen" || $2 == "tenant_new"' | wc -l)]"
echo "again: $(sh "$SCRIPT")"
kill "$ACCOUNT"
HOST

out=$(unshare -rn bash "$W/host.sh" 2>&1)
echo "$out" | sed 's/^/     | /'
check "the transaction applies on a live netns" "echo \"\$out\" | grep -q '^apply: tenant-egress-limits: applied (smtp blocked, new connections 20/s, scans over 5 then 2/min new destinations, on ports 80 and 443 over 10 then 4/min, blocked 2m)'"
check "SMTP to the outside is marked" "echo \"\$out\" | grep -q '^smtp: $TN_TENANT_ACCOUNT'"
check "three new destinations block nothing" "echo \"\$out\" | grep -q '^three new destinations: blocked=\[\]'"
check "eight new web destinations block nothing" "echo \"\$out\" | grep -q '^eight new web destinations: blocked=\[\]'"
check "a scan blocks its source, the same addresses on the web ports not seen for it" "echo \"\$out\" | grep -q '^scan: blocked=\[$TN_TENANT_ACCOUNT\]'"
check "a scan of the web ports over their own limit blocks too" "echo \"\$out\" | grep -q '^web scan: blocked=\[$TN_TENANT_ACCOUNT\]'"
check "re-applying keeps the block" "echo \"\$out\" | grep -q '^after re-apply: blocked=\[$TN_TENANT_ACCOUNT\]'"
check "a blocked source still reaches this server's published ports, and nothing else" "echo \"\$out\" | grep -q '^published port while blocked: packets 1 packets 0 '"
check "a flood to one destination is marked, not blocked" "echo \"\$out\" | grep -q '^flood: $TN_TENANT_ACCOUNT blocked=\[\]'"
check "a table of another shape is rebuilt" "echo \"\$out\" | grep -q '^other shape: tenant-egress-limits: rebuilt, blocks and counters reset'"
check "a table holding hand-made sets or chains is replaced" "echo \"\$out\" | grep -q '^hand-made objects: tenant-egress-limits: rebuilt, blocks and counters reset.* left=\[0\]'"
check "and the next run keeps it" "echo \"\$out\" | grep -q '^again: tenant-egress-limits: applied'"

[ "$fails" = 0 ] && echo "all passed" || echo "$fails failed"
exit "$fails"
