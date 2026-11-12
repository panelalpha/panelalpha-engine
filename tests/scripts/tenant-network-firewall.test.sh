#!/bin/bash
# Exercises how tenant-network-firewall.sh picks and records the network's
# prefix, against fake docker/ip/iptables commands. The host's routes and
# addresses, Docker's networks and pash-tenants itself are files in $W.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../scripts" && pwd)"
. "$(dirname "${BASH_SOURCE[0]}")/test-network.sh"
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
        esac
        exit 0
    fi
    for id in "\${@:3}"; do awk -v id="\$id" '\$1 == id { print \$2 " " }' "\$W/nets" 2>/dev/null; done ;;
"network ls") awk '{ print \$1 }' "\$W/nets" 2>/dev/null ;;
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
esac
FAKE
# A stand-in for nft: logs the call and applies the transaction to nft.state, all or nothing.
cat >"$W/nft.awk" <<'MODEL'
function fail(why) { print "nft: " why >"/dev/stderr"; failed = 1; exit 1 }
function forget_rules(   i) { for (i = 1; i <= n; i++) delete rule[i]; n = 0 }
function forget_all(   k) { for (k in sets) delete sets[k]; for (k in elems) delete elems[k]; for (k in chains) delete chains[k]; forget_rules() }
FILENAME == ARGV[1] {
    if ($1 == "table") table = 1
    else if ($1 == "set") sets[$2] = 1
    else if ($1 == "elem") { e = $0; sub(/^elem [^ ]+ /, "", e); elems[$2 SUBSEP e] = 1 }
    else if ($1 == "chain") chains[$2] = 1
    else if ($1 == "rule") rule[++n] = $0
    next
}
{ line = $0; sub(/^[ \t]+/, "", line); sub(/[ \t]+$/, "", line) }
line == "" { next }
depth == 0 && line == "add table bridge pa_tenants" { table = 1; next }
depth == 0 && line == "delete table bridge pa_tenants" { if (!table) fail("no such table"); table = 0; forget_all(); next }
depth == 0 && line == "flush table bridge pa_tenants" { if (!table) fail("no such table"); forget_rules(); next }
depth == 0 && line == "table bridge pa_tenants {" { table = 1; depth = 1; next }
depth == 1 && line ~ /^(set|chain) [a-z_]+ \{$/ { kind = $1; name = $2; if (kind == "set") sets[name] = 1; else chains[name] = 1; depth = 2; next }
depth == 2 && line == "}" { depth = 1; next }
depth == 1 && line == "}" { depth = 0; next }
depth == 2 && line ~ /^type / { next }
depth == 2 && kind == "set" && line ~ /^elements = \{ .* \}$/ {
    body = line; sub(/^elements = \{ /, "", body); sub(/ \}$/, "", body)
    count = split(body, parts, ", ")
    for (i = 1; i <= count; i++) elems[name SUBSEP parts[i]] = 1
    next
}
depth == 2 && kind == "chain" { rule[++n] = "rule " name " " line; next }
{ fail("cannot read: " line) }
END {
    if (failed) exit 1
    if (depth) fail("unclosed block")
    if (table) print "table"
    for (k in sets) print "set " k
    for (k in elems) { split(k, p, SUBSEP); print "elem " p[1] " " p[2] }
    for (k in chains) print "chain " k
    for (i = 1; i <= n; i++) print rule[i]
}
MODEL
cat >"$W/bin/nft" <<FAKE
#!/bin/bash
cat >"$W/nft.in"
echo "nft \$*" >>"$W/nft.calls"
touch "$W/nft.state"
awk -f "$W/nft.awk" "$W/nft.state" "$W/nft.in" >"$W/nft.next" && mv "$W/nft.next" "$W/nft.state"
FAKE
cat >"$W/bin/service" <<FAKE
#!/bin/bash
echo "service \$*" >>"$W/calls"
rm -f "$W/flushed"
FAKE
cat >"$W/bin/iptables" <<FAKE
#!/bin/bash
# -w 30 (wait for the xtables lock) is recorded apart; a call without it in no-wait.
if [ "\$1 \$2" = "-w 30" ]; then shift 2; else echo "iptables \$*" >>"$W/no-wait"; fi
echo "iptables \$*" >>"$W/calls"
case "\$*" in *" -C "* | "-C "* | *"-t nat -C"*) exit 1 ;; esac
exit 0
FAKE
printf '#!/bin/bash\necho "$*" >"%s/restore.args"\ncat >"%s/restore.in"\n' "$W" "$W" >"$W/bin/iptables-restore"
printf '#!/bin/bash\necho "systemctl $*" >>"%s/calls"\n' "$W" >"$W/bin/systemctl"
printf '#!/bin/bash\nexit 0\n' >"$W/bin/flock"
chmod +x "$W/bin/"*

# The host: a default route and a LAN. T<n>: the first two octets of the /16
# <n> places after the engine's default tenant network, as .env records them.
GATEWAY=$(tn_host PUBLIC_3 1)
LAN_IP=$(tn_host PRIVATE 25)
LAN_ROUTER=$(tn_host PRIVATE 1)
LAN=$(tn_prefix "$LAN_IP" 20)
LAN_BROADCAST=$(tn_add "${LAN%/*}" 4095)
tenant_prefix() {
    local net
    net=$(tn_add "${TN_TENANT_NETWORK_CIDR%/*}" $(($1 << 16)))
    echo "${net%.*.*}"
}
T0=$(tenant_prefix 0)
T1=$(tenant_prefix 1)
T2=$(tenant_prefix 2)
T3=$(tenant_prefix 3)
T4=$(tenant_prefix 4)
T7=$(tenant_prefix 7)
T50=$(tenant_prefix 50)
CANDIDATES=$(tn_prefix "$T0.0.0" 11)

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
        "$W/nft.in" "$W/nft.state" "$W/nft.calls" "$W/restore.in" "$W/restore.args" "$W/no-wait" "$W/units" \
        "$W/api-access" "$W/stdin"
    printf 'default via %s dev eth0\n%s dev eth0 proto kernel scope link src %s\n' "$GATEWAY" "$LAN" "$LAN_IP" >"$W/routes"
    printf '1: lo    inet %s/8 scope host lo\n2: eth0    inet %s/20 brd %s scope global eth0\n' "$TN_LOOPBACK" "$LAN_IP" "$LAN_BROADCAST" >"$W/addrs"
    printf 'COMPOSE_PROFILES=full\n' >"$W/env"
    : >"$W/stdin"
}
run() { # run [args...] -> exit code, with \$W/stdin as the broker's lines
    PATH="$W/bin:$PATH" PA_ENV_FILE="$W/env" PA_TENANT_LOCK="$W/lock" PA_SYSTEMD_DIR="$W/units" \
        PA_API_ACCESS_FILE="$W/api-access" \
        sh "$SCRIPT_DIR/tenant-network-firewall.sh" "$@" <"$W/stdin" >"$W/out" 2>&1
    echo $?
}
recorded() { sed -n 's/^TENANT_NETWORK_PREFIX=//p' "$W/env" | tr '\n' ' ' | sed 's/ $//'; }
created() { cat "$W/tenants" 2>/dev/null; }
bound() { sed -n "s/^elem $1 //p" "$W/nft.state" 2>/dev/null | LC_ALL=C sort | tr '\n' ',' | sed 's/,$//'; }
sets() { sed -n 's/^set //p' "$W/nft.state" 2>/dev/null | sort | tr '\n' ' ' | sed 's/ $//'; }
rules() { grep -c '^rule ports ' "$W/nft.state" 2>/dev/null; }
transactions() { cat "$W/nft.calls" 2>/dev/null | grep -c '^nft -f -$'; }

reset
expect "a fresh host: created" "0" "$(run --create)"
expect "on $T0.0.0/16" "$T0.0.0/16" "$(created)"
expect "and recorded in .env" "$T0" "$(recorded)"
expect "a second run changes nothing" "0" "$(run --create)"
expect "and records it once" "$T0" "$(recorded)"

reset; echo "$T0.5.0/24 via $LAN_ROUTER dev eth0" >>"$W/routes"
expect "a host route inside $T0: next prefix" "0" "$(run --create)"
expect "$T1" "$T1.0.0/16" "$(created)"

reset; printf 'a1 %s.0.0/20\nb2 %s.0.0/16\n' "$T0" "$T1" >"$W/nets"
expect "Docker networks on $T0 and $T1: skipped" "0" "$(run --create)"
expect "$T2" "$T2.0.0/16" "$(created)"

reset; echo "3: wg0    inet $T3.0.7/32 scope global wg0" >>"$W/addrs"; printf 'a1 %s.0.0/16\nb2 %s.0.0/16\nc3 %s.0.0/16\n' "$T0" "$T1" "$T2" >"$W/nets"
expect "an interface address inside $T3: skipped too" "0" "$(run --create)"
expect "$T4" "$T4.0.0/16" "$(created)"

reset; echo "$CANDIDATES via $LAN_ROUTER dev eth0" >>"$W/routes"
expect "every candidate taken: refused" "1" "$(run --create)"
expect "nothing created" "" "$(created)"
expect "and the operator is told what to set" "1" "$(grep -c 'set TENANT_NETWORK_PREFIX' "$W/out")"

reset; echo "TENANT_NETWORK_PREFIX=$T50" >>"$W/env"
expect "a prefix in .env that is free: used" "0" "$(run --create)"
expect "as it is" "$T50.0.0/16" "$(created)"
expect ".env keeps one line" "$T50" "$(recorded)"

reset; echo "TENANT_NETWORK_PREFIX=${LAN_IP%.*.*}" >>"$W/env"
expect "a prefix in .env overlapping the LAN: refused" "1" "$(run --create)"
expect "nothing created" "" "$(created)"

reset; echo "TENANT_NETWORK_PREFIX=10.x" >>"$W/env"
expect "a prefix that is not two octets: refused" "1" "$(run --create)"

reset; echo "$T0.0.0/16" >"$W/tenants"
expect "an existing network, no prefix in .env" "0" "$(run)"
expect "its prefix is recorded" "$T0" "$(recorded)"

reset; echo "$T7.0.0/16" >"$W/tenants"; echo "TENANT_NETWORK_PREFIX=$T0" >>"$W/env"
expect "an existing network that disagrees with .env: rules follow the network" "0" "$(run)"
expect "with a warning" "1" "$(grep -c "using $T7" "$W/out")"
expect ".env is not overwritten" "$T0" "$(recorded)"

reset; printf 'COMPOSE_PROFILES=full' >"$W/env"
expect ".env without a final newline" "0" "$(run --create)"
expect "gets the prefix on a line of its own" "TENANT_NETWORK_PREFIX=$T0" "$(tail -n 1 "$W/env")"

reset; touch "$W/flushed"
expect "flushed chains without --restart-docker: fails" "1" "$(run --create)"
expect "and Docker is not restarted" "0" "$(grep -c '^service docker restart' "$W/calls")"

reset; touch "$W/flushed"
expect "flushed chains with --restart-docker: created" "0" "$(run --create --restart-docker)"
expect "after one restart" "1" "$(grep -c '^service docker restart' "$W/calls")"

# The root broker asks for the prefix and makes the network itself.
reset; printf 'a1 %s.0.0/16\n' "$T2" >"$W/nets"
printf 'network %s.0.0/20\nnetwork %s.0.0/16\nnetwork fd00:1::/64\n' "$T0" "$T1" >"$W/stdin"
expect "the broker's Docker networks on $T0 and $T1: $T2 printed" "0|$T2" "$(run --prefix)|$(cat "$W/out")"
expect "recorded as --create records it" "$T2" "$(recorded)"
expect "and made by the broker, not here" "" "$(created)"
expect "docker's CLI is never asked" "0" "$(cat "$W/calls" 2>/dev/null | grep -c '^docker')"

reset; echo "$CANDIDATES via $LAN_ROUTER dev eth0" >>"$W/routes"
expect "every candidate taken: the broker is refused a prefix" "1" "$(run --prefix)"
expect "with what to set" "1" "$(grep -c 'set TENANT_NETWORK_PREFIX' "$W/out")"
expect "and nothing recorded" "" "$(recorded)"

# Every port the broker names is bound to its MAC and address.
reset; echo "$T0.0.0/16" >"$W/tenants"
printf 'member shared-hosting-sites-db-1 %s.0.2\nport veth03d7e26 9a:2a:26:9f:10:1c %s.0.2\nmember alice %s.128.1\nport veth4d66117 26:e3:f2:d4:b6:68 %s.128.1\nmember stopped %s.128.9\n' \
    "$T0" "$T0" "$T0" "$T0" "$T0" >"$W/stdin"
expect "ports bound" "0" "$(run --ports-on-stdin)"
expect "sites-db on the port of its own interface on the network" "1" \
    "$(grep -c "\"veth03d7e26\" . 9a:2a:26:9f:10:1c . $T0.0.2" "$W/nft.in")"
expect "and its ARP with the same MAC as sender" "1" \
    "$(grep -c "\"veth03d7e26\" . 9a:2a:26:9f:10:1c . 9a:2a:26:9f:10:1c . $T0.0.2" "$W/nft.in")"
expect "an account on its port" "1" "$(grep -c "\"veth4d66117\" . 26:e3:f2:d4:b6:68 . $T0.128.1" "$W/nft.in")"
expect "a member the broker bound to no port is not bound" "0" "$(grep -c "$T0.128.9" "$W/nft.in")"
expect "a port of another network is not bound" "0" "$(grep -c vethcore0 "$W/nft.in")"
expect "the table is replaced in one transaction" "1" "$(grep -c '^delete table bridge pa_tenants' "$W/nft.in")"
expect "frames that match nothing are dropped" "1" "$(grep -c 'counter drop' "$W/nft.in")"
expect "only on this bridge" "1" "$(grep -c 'meta ibrname != "br-pa-tenants" accept' "$W/nft.in")"
expect "reported" "1" "$(grep -c '2 port(s) bound' "$W/out")"

reset; echo "$T0.0.0/16" >"$W/tenants"
expect "no lines from the broker on a fresh host: applied" "0" "$(run)"
expect "both sets made" "arp_ok ip_ok" "$(sets)"
expect "with nothing bound, so every port is cut off" "|" "$(bound arp_ok)|$(bound ip_ok)"
expect "and every frame judged" "4" "$(rules)"
expect "the sets have no elements" "0" "$(grep -c 'elements' "$W/nft.in")"

# What the broker bound stays bound through any run that cannot name the ports.
SITES_DB_PORT="\"veth03d7e26\" . 9a:2a:26:9f:10:1c . $T0.0.2"
ALICE_PORT="\"veth4d66117\" . 26:e3:f2:d4:b6:68 . $T0.128.1"
printf 'member shared-hosting-sites-db-1 %s.0.2\nport veth03d7e26 9a:2a:26:9f:10:1c %s.0.2\nmember alice %s.128.1\nport veth4d66117 26:e3:f2:d4:b6:68 %s.128.1\n' \
    "$T0" "$T0" "$T0" "$T0" >"$W/stdin"
expect "the broker binds two ports" "0" "$(run --ports-on-stdin)"
expect "both bound" "$SITES_DB_PORT,$ALICE_PORT" "$(bound ip_ok)"
arp_before=$(bound arp_ok)
: >"$W/stdin"; : >"$W/nft.calls"
expect "a run without the broker's lines" "0" "$(run)"
expect "leaves both bound" "$SITES_DB_PORT,$ALICE_PORT" "$(bound ip_ok)"
expect "and their ARP" "$arp_before" "$(bound arp_ok)"
expect "in one transaction" "1" "$(transactions)"
expect "that never takes the table away" "0" "$(grep -c '^delete table bridge pa_tenants' "$W/nft.in")"
expect "nor names a port" "0" "$(grep -c 'elements' "$W/nft.in")"
expect "the rules are replaced, not added to" "4" "$(rules)"
expect "and it says the binding is kept" "1" "$(grep -c 'its port bindings kept' "$W/out")"
expect "boot leaves them bound too" "0|$SITES_DB_PORT,$ALICE_PORT" "$(run --boot)|$(bound ip_ok)"
printf 'member bob %s.128.2\nport vethb0b0001 52:54:00:12:34:56 %s.128.2\n' "$T0" "$T0" >"$W/stdin"
expect "the broker's next lines" "0" "$(run --ports-on-stdin)"
expect "replace them with the port they name" "\"vethb0b0001\" . 52:54:00:12:34:56 . $T0.128.2" "$(bound ip_ok)"
expect "its ARP alone" "\"vethb0b0001\" . 52:54:00:12:34:56 . 52:54:00:12:34:56 . $T0.128.2" "$(bound arp_ok)"
printf 'subnet %s.0.0/16\nmember stopped %s.128.9\n' "$T0" "$T0" >"$W/stdin"
expect "lines naming no port" "0" "$(run --ports-on-stdin)"
expect "unbind every port" "|" "$(bound arp_ok)|$(bound ip_ok)"

# The network's subnet as the broker read it through the host's Engine API.
reset; printf 'subnet %s.0.0/16\nmember alice %s.128.1\n' "$T7" "$T7" >"$W/stdin"
expect "the broker's subnet: applied" "0" "$(run --ports-on-stdin)"
expect "on its addresses" "1" "$(grep -c -- "-d $T7.0.2/32 -p tcp --dport 3306 -j ACCEPT" "$W/restore.in")"
expect "its prefix recorded" "$T7" "$(recorded)"
expect "docker's CLI is never asked" "0" "$(grep -c '^docker' "$W/calls")"

# Before Docker, from .env alone, everything closed until bound.
reset; echo "TENANT_NETWORK_PREFIX=$T50" >>"$W/env"
expect "boot: applied without Docker" "0" "$(run --boot)"
expect "Docker is never asked" "0" "$(grep -c '^docker' "$W/calls")"
expect "the chains from the prefix in .env" "1" "$(grep -c -- "-d $T50.0.2/32 -p tcp --dport 3306 -j ACCEPT" "$W/restore.in")"
expect "DOCKER-USER made for Docker to keep" "1" "$(grep -c '^iptables -N DOCKER-USER' "$W/calls")"
expect "and jumped to before Docker's own rules" "1" "$(grep -c '^iptables -I DOCKER-USER -i br-pa-tenants -j PA-TENANT-NET' "$W/calls")"
expect "every iptables call waits for the xtables lock" "0|-w 30 --noflush" "$(cat "$W/no-wait" 2>/dev/null | wc -l)|$(cat "$W/restore.args")"
expect "no port bound" "0|arp_ok ip_ok||" "$(grep -c 'elements' "$W/nft.in")|$(sets)|$(bound arp_ok)|$(bound ip_ok)"
expect "so every port of the bridge is dropped" "1" "$(grep -c 'counter drop' "$W/nft.in")"
expect "and says so" "1" "$(grep -c 'closed until its ports are bound' "$W/out")"

reset
expect "boot without a prefix in .env" "0" "$(run --boot)"
expect "uses docker-compose.yml's default" "1" "$(grep -c -- "-d $T0.0.2/32" "$W/restore.in")"

reset; echo "TENANT_NETWORK_PREFIX=10.x" >>"$W/env"
expect "boot with a prefix that is not two octets: refused" "1" "$(run --boot)"
expect "nothing applied" "no" "$([ -f "$W/restore.in" ] && echo yes || echo no)"

reset; mkdir -p "$W/units"
expect "units installed" "0" "$(run --install-units)"
guard="$W/units/panelalpha-tenant-guard.service"
bind="$W/units/panelalpha-tenant-bind.service"
expect "the guard runs before Docker" "1" "$(grep -c '^Before=docker.service$' "$guard")"
expect "done once per Docker start, not again while Docker runs" "1" "$(grep -c '^RemainAfterExit=yes$' "$guard")"
expect "again before every Docker restart, so no engine-port rule names an address from before it" "1" \
    "$(grep -c '^PartOf=docker.service$' "$guard")"
expect "with --boot" "1" "$(grep -c "^ExecStart=/bin/sh $SCRIPT_DIR/tenant-network-firewall.sh --boot$" "$guard")"
expect "pulled in by Docker itself" "1" "$(grep -c '^WantedBy=docker.service multi-user.target$' "$guard")"
expect "no unit runs it after a Docker start: the root broker applies it then" "guard" \
    "$(ls "$W/units" | sed 's/^panelalpha-tenant-//; s/\.service$//' | tr '\n' ' ' | sed 's/ $//')"
expect "the guard alone enabled, after a reload" "systemctl daemon-reload|systemctl enable panelalpha-tenant-guard.service" \
    "$(grep '^systemctl' "$W/calls" | tr '\n' '|' | sed 's/|$//')"
expect "nothing to remove on a host without the bind unit" "0" "$(grep -c 'removed' "$W/out")"
expect "Docker untouched" "0" "$(grep -c '^docker' "$W/calls")"

# A leftover bind unit is disabled and deleted before the reload.
reset; mkdir -p "$W/units"
printf '[Service]\nExecStart=/bin/sh %s\n' "$SCRIPT_DIR/tenant-network-firewall.sh" >"$bind"
expect "units installed over an older release's" "0" "$(run --install-units)"
expect "its bind unit is gone" "no" "$([ -e "$bind" ] && echo yes || echo no)"
expect "disabled, deleted, then systemd reloaded" \
    "systemctl disable panelalpha-tenant-bind.service|systemctl daemon-reload|systemctl enable panelalpha-tenant-guard.service" \
    "$(grep '^systemctl' "$W/calls" | tr '\n' '|' | sed 's/|$//')"
expect "and says so" "1" "$(grep -c '^tenant-network-firewall: panelalpha-tenant-bind.service removed$' "$W/out")"
expect "the guard is kept" "1" "$(grep -c "^ExecStart=/bin/sh $SCRIPT_DIR/tenant-network-firewall.sh --boot$" "$guard")"
expect "a second install finds nothing to remove" "0|0" "$(run --install-units)|$(grep -c 'removed' "$W/out")"

# The engine's allow-list: which accounts may reach :2011, by their own
# address on the bridge.
reset; echo "$T0.0.0/16" >"$W/tenants"
printf 'member alice %s.128.1\nmember bob %s.128.2\n' "$T0" "$T0" >"$W/stdin"
expect "no allow-list file: no account may open the engine port" "0" "$(run --ports-on-stdin)"
expect "the engine port is not opened" "0" "$(grep -c -- '--ctorigdstport 2011' "$W/restore.in")"
: >"$W/api-access"
expect "an empty allow-list file: no account may open the engine port" "0" "$(run --ports-on-stdin)"
expect "the engine port is not opened" "0" "$(grep -c -- '--ctorigdstport 2011' "$W/restore.in")"

printf 'alice\n' >"$W/api-access"
expect "one account listed: it may" "0" "$(run --ports-on-stdin)"
expect "its own address on the port of the DNAT" "1" \
    "$(grep -cF -- "-A PA-TENANT-NET -i br-pa-tenants -s $T0.128.1/32 -p tcp -m conntrack --ctstate DNAT --ctorigdstport 2011 -j ACCEPT" "$W/restore.in")"
expect "and no one else" "0" "$(grep -c "$T0.128.2" "$W/restore.in")"
expect "it comes before the private ranges are refused" "1" \
    "$(awk '/ctorigdstport 2011/ { api = NR } /-j REJECT --reject-with icmp-net-prohibited/ { if (!reject) reject = NR } END { print (api && reject && api < reject) ? 1 : 0 }' "$W/restore.in")"

printf 'alice\nbob\n' >"$W/api-access"
expect "two accounts listed: both may" "0" "$(run --ports-on-stdin)"
expect "the first" "1" "$(grep -c "$T0.128.1" "$W/restore.in")"
expect "and the second" "1" "$(grep -c "$T0.128.2" "$W/restore.in")"

expect "without the broker's lines, a listed account may not" "0" "$(run)"
expect "the engine port is left closed" "0" "$(grep -c -- '--ctorigdstport 2011' "$W/restore.in")"

printf 'alice\nstopped\n' >"$W/api-access"
expect "a listed account the broker did not name: no rule" "0" "$(run --ports-on-stdin)"
expect "the running one still may" "1" "$(grep -c "$T0.128.1" "$W/restore.in")"
expect "one rule in all, none invented for the stopped account" "1" "$(grep -c -- '--ctorigdstport 2011' "$W/restore.in")"
expect "and none for the account that is not on the bridge" "0" "$(grep -c "$T0.128.2" "$W/restore.in")"

expect "boot: the allow-list is not read and Docker is not asked" "0" "$(run --boot)"
expect "no engine port rule before its ports are bound" "0" "$(grep -c -- '--ctorigdstport 2011' "$W/restore.in")"

echo
[ "$failures" = 0 ] && echo "All passed." || echo "$failures failed."
exit "$failures"
