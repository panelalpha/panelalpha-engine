#!/bin/bash
# Exercises how tenant-network-firewall.sh picks and records the network's
# prefix, against fake docker/ip/iptables commands. The host's routes and
# addresses, Docker's networks and pash-tenants itself are files in $W.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT
mkdir -p "$W/bin"

# `routes`, `addrs`: ip's output. `nets`: "id subnet" per Docker network.
# `tenants`: pash-tenants' subnet once it exists. `flushed`: chains are gone.
cat >"$W/bin/docker" <<FAKE
#!/bin/bash
W="$W"
echo "docker \$*" >>"\$W/calls"
case "\$1 \$2" in
"network inspect")
    if [[ " \$* " == *" pash-tenants "* ]]; then
        [ -f "\$W/tenants" ] || exit 1
        case "\$*" in
        *Subnet*) echo "\$(cat "\$W/tenants") " ;;
        *MacAddress*) cat "\$W/members" 2>/dev/null ;;
        esac
        exit 0
    fi
    for id in "\${@:3}"; do awk -v id="\$id" '\$1 == id { print \$2 " " }' "\$W/nets" 2>/dev/null; done ;;
"network ls") awk '{ print \$1 }' "\$W/nets" 2>/dev/null ;;
"inspect -f") awk -v n="\${!#}" '\$1 == n { print \$2 }' "\$W/pids" 2>/dev/null ;;
"network create")
    if [ -f "\$W/flushed" ]; then
        echo "Error response from daemon: Failed to Setup IP tables: iptables: No chain/target/match by that name." >&2
        exit 1
    fi
    for a in "\$@"; do [ "\$prev" = --subnet ] && echo "\$a" >"\$W/tenants"; prev=\$a; done ;;
"info "* | "info") exit 0 ;;
esac
FAKE
cat >"$W/bin/ip" <<FAKE
#!/bin/bash
case "\$*" in
*route*) cat "$W/routes" 2>/dev/null ;;
*addr*) cat "$W/addrs" 2>/dev/null ;;
"-o link") cat "$W/hostlinks" 2>/dev/null ;;
esac
FAKE
# \`nsenter -t PID -n ip -o link\`: the links inside that container, from \$W/ns-PID.
cat >"$W/bin/nsenter" <<FAKE
#!/bin/bash
cat "$W/ns-\$2" 2>/dev/null
FAKE
# Without \$W/nft-missing it stands in for nft and keeps the ruleset it is given.
cat >"$W/bin/nft" <<FAKE
#!/bin/bash
cat >"$W/nft.in"
FAKE
cat >"$W/bin/service" <<FAKE
#!/bin/bash
echo "service \$*" >>"$W/calls"
rm -f "$W/flushed"
FAKE
cat >"$W/bin/iptables" <<'FAKE'
#!/bin/bash
case "$*" in *" -C "* | "-C "* | *"-t nat -C"*) exit 1 ;; esac
exit 0
FAKE
printf '#!/bin/bash\ncat >/dev/null\n' >"$W/bin/iptables-restore"
printf '#!/bin/bash\nexit 0\n' >"$W/bin/systemctl"
printf '#!/bin/bash\nexit 0\n' >"$W/bin/flock"
chmod +x "$W/bin/"*

failures=0
expect() { # expect <label> <expected> <actual>
    if [ "$2" = "$3" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected '$2', got '$3'"
        failures=$((failures + 1))
    fi
}
reset() {
    rm -f "$W/routes" "$W/addrs" "$W/nets" "$W/tenants" "$W/flushed" "$W/calls" \
        "$W/members" "$W/pids" "$W/hostlinks" "$W"/ns-* "$W/nft.in"
    printf 'default via 192.0.2.1 dev eth0\n10.10.0.0/20 dev eth0 proto kernel scope link src 10.10.0.25\n' >"$W/routes"
    printf '1: lo    inet 127.0.0.1/8 scope host lo\n2: eth0    inet 10.10.0.25/20 brd 10.10.15.255 scope global eth0\n' >"$W/addrs"
    printf 'COMPOSE_PROFILES=full\n' >"$W/env"
}
run() { # run [args...] -> exit code
    PATH="$W/bin:$PATH" PA_ENV_FILE="$W/env" PA_TENANT_LOCK="$W/lock" \
        sh "$SCRIPT_DIR/tenant-network-firewall.sh" "$@" >"$W/out" 2>&1
    echo $?
}
recorded() { sed -n 's/^TENANT_NETWORK_PREFIX=//p' "$W/env" | tr '\n' ' ' | sed 's/ $//'; }
created() { cat "$W/tenants" 2>/dev/null; }

reset
expect "a fresh host: created" "0" "$(run --create)"
expect "on 10.200.0.0/16" "10.200.0.0/16" "$(created)"
expect "and recorded in .env" "10.200" "$(recorded)"
expect "a second run changes nothing" "0" "$(run --create)"
expect "and records it once" "10.200" "$(recorded)"

reset; echo "10.200.5.0/24 via 10.10.0.1 dev eth0" >>"$W/routes"
expect "a host route inside 10.200: next prefix" "0" "$(run --create)"
expect "10.201" "10.201.0.0/16" "$(created)"

reset; printf 'a1 10.200.0.0/20\nb2 10.201.0.0/16\n' >"$W/nets"
expect "Docker networks on 10.200 and 10.201: skipped" "0" "$(run --create)"
expect "10.202" "10.202.0.0/16" "$(created)"

reset; echo "3: wg0    inet 10.203.0.7/32 scope global wg0" >>"$W/addrs"; printf 'a1 10.200.0.0/16\nb2 10.201.0.0/16\nc3 10.202.0.0/16\n' >"$W/nets"
expect "an interface address inside 10.203: skipped too" "0" "$(run --create)"
expect "10.204" "10.204.0.0/16" "$(created)"

reset; echo "10.192.0.0/11 via 10.10.0.1 dev eth0" >>"$W/routes"
expect "every candidate taken: refused" "1" "$(run --create)"
expect "nothing created" "" "$(created)"
expect "and the operator is told what to set" "1" "$(grep -c 'set TENANT_NETWORK_PREFIX' "$W/out")"

reset; echo "TENANT_NETWORK_PREFIX=10.250" >>"$W/env"
expect "a prefix in .env that is free: used" "0" "$(run --create)"
expect "as it is" "10.250.0.0/16" "$(created)"
expect ".env keeps one line" "10.250" "$(recorded)"

reset; echo "TENANT_NETWORK_PREFIX=10.10" >>"$W/env"
expect "a prefix in .env overlapping the LAN: refused" "1" "$(run --create)"
expect "nothing created" "" "$(created)"

reset; echo "TENANT_NETWORK_PREFIX=10.x" >>"$W/env"
expect "a prefix that is not two octets: refused" "1" "$(run --create)"

reset; echo "10.200.0.0/16" >"$W/tenants"
expect "an existing network, no prefix in .env" "0" "$(run)"
expect "its prefix is recorded" "10.200" "$(recorded)"

reset; echo "10.207.0.0/16" >"$W/tenants"; echo "TENANT_NETWORK_PREFIX=10.200" >>"$W/env"
expect "an existing network that disagrees with .env: rules follow the network" "0" "$(run)"
expect "with a warning" "1" "$(grep -c 'using 10.207' "$W/out")"
expect ".env is not overwritten" "10.200" "$(recorded)"

reset; printf 'COMPOSE_PROFILES=full' >"$W/env"
expect ".env without a final newline" "0" "$(run --create)"
expect "gets the prefix on a line of its own" "TENANT_NETWORK_PREFIX=10.200" "$(tail -n 1 "$W/env")"

reset; touch "$W/flushed"
expect "flushed chains without --restart-docker: fails" "1" "$(run --create)"
expect "and Docker is not restarted" "0" "$(grep -c '^service docker restart' "$W/calls")"

reset; touch "$W/flushed"
expect "flushed chains with --restart-docker: created" "0" "$(run --create --restart-docker)"
expect "after one restart" "1" "$(grep -c '^service docker restart' "$W/calls")"

# engine#529: every running member is bound to its port, MAC and address.
reset; echo "10.200.0.0/16" >"$W/tenants"
printf 'shared-hosting-sites-db-1 9a:2a:26:9f:10:1c 10.200.0.2/16\nalice 26:e3:f2:d4:b6:68 10.200.128.1/16\nstopped 02:00:00:00:00:09 10.200.128.9/16\n' >"$W/members"
printf 'shared-hosting-sites-db-1 4242\nalice 4343\nstopped 0\n' >"$W/pids"
printf '1: lo: <LOOPBACK> mtu 65536\n2: eth0@if1180: <UP> link/ether 02:aa:aa:aa:aa:aa\n3: eth1@if1183: <UP> link/ether 9a:2a:26:9f:10:1c\n' >"$W/ns-4242"
printf '1: lo: <LOOPBACK> mtu 65536\n2: eth1@if1179: <UP> link/ether 26:e3:f2:d4:b6:68\n' >"$W/ns-4343"
printf '1179: veth4d66117@if2: <UP>\n1180: vethcore0@if2: <UP>\n1183: veth03d7e26@if3: <UP>\n' >"$W/hostlinks"
expect "ports bound" "0" "$(run)"
expect "sites-db on the port of its own interface on the network" "1" \
    "$(grep -c '"veth03d7e26" . 9a:2a:26:9f:10:1c . 10.200.0.2' "$W/nft.in")"
expect "and its ARP with the same MAC as sender" "1" \
    "$(grep -c '"veth03d7e26" . 9a:2a:26:9f:10:1c . 9a:2a:26:9f:10:1c . 10.200.0.2' "$W/nft.in")"
expect "an account on its port" "1" "$(grep -c '"veth4d66117" . 26:e3:f2:d4:b6:68 . 10.200.128.1' "$W/nft.in")"
expect "a stopped container is not bound" "0" "$(grep -c 10.200.128.9 "$W/nft.in")"
expect "sites-db's interface on another network is not bound" "0" "$(grep -c vethcore0 "$W/nft.in")"
expect "the table is replaced in one transaction" "1" "$(grep -c '^delete table bridge pa_tenants' "$W/nft.in")"
expect "frames that match nothing are dropped" "1" "$(grep -c 'counter drop' "$W/nft.in")"
expect "only on this bridge" "1" "$(grep -c 'meta ibrname != "br-pa-tenants" accept' "$W/nft.in")"
expect "reported" "1" "$(grep -c '2 port(s) bound' "$W/out")"

reset; echo "10.200.0.0/16" >"$W/tenants"
expect "no members: an empty binding, so every port is cut off" "0" "$(run)"
expect "the sets have no elements" "0" "$(grep -c 'elements' "$W/nft.in")"

echo
[ "$failures" = 0 ] && echo "All passed." || echo "$failures failed."
exit "$failures"
