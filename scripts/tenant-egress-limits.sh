#!/bin/sh
# Default limits on the new connections projects and host builds open to the
# rest of the world, so one app cannot scan, flood or send spam from the
# server's address and leave the operator to hear of it from the provider.
#
# The accounts' network (tenant-network-firewall.sh) and the host builds'
# network (build-network-firewall.sh) already decide where traffic may go.
# This only drops; what it lets through is still judged there. It is an nft
# table of the inet family on the forward hook, ahead of Docker's chains, and
# it sees a connection's first packet only (ct state new) when it leaves one
# of those bridges for anything but another of them:
#   - SMTP (tcp/25) to the outside is dropped; projects send mail through the
#     server's own relay, which is the host and so never forwarded,
#   - more than EGRESS_NEW_CONNECTIONS_PER_SECOND new connections a second
#     from one address (burst ten times that) are dropped,
#   - more new destinations than EGRESS_NEW_DESTINATIONS_BURST at once and
#     then EGRESS_NEW_DESTINATIONS_PER_MINUTE a minute is a scan: the address
#     loses every new outbound connection for EGRESS_SCAN_BLOCK_MINUTES (0
#     drops only the destinations over the limit). A pair of addresses seen in
#     the last 10 minutes is not new.
#   - the web ports (tcp/80 and tcp/443) are counted apart, against
#     EGRESS_WEB_NEW_DESTINATIONS_BURST and _PER_MINUTE, since federation,
#     feeds and monitors reach many servers there; a pair seen on them is not
#     seen on any other port, so they never open a way past the scan limit.
# A connection Docker forwards to one of this server's published ports (DNAT),
# such as the engine API a project may call, stays on the server and is not
# limited, so a blocked project keeps its engine API.
# A setting of 0 turns its limit off; the defaults below apply without .env.
# The root broker reads the marks (blocked, flood, smtp) to name the project,
# moves or clears an address's state when its container changes, and makes a
# project's next block within a day twice as long as its last, up to a day.
#
# Usage: tenant-egress-limits.sh [--print | --settings] [--source <bridge>]...
# Idempotent. Re-applying keeps the sets, so a block and the counters survive
# every run; a table of another shape is replaced, which resets them. The root
# broker re-applies within a minute, so a changed setting needs no restart.
# --print writes the transaction instead of applying it; --settings prints the
# values in force, one NAME=value a line.

ENV_FILE="${PA_ENV_FILE:-/opt/panelalpha/shared-hosting/.env}"
TABLE='inet pa_egress'
SOURCES='"br-pa-tenants", "br-pa-build"'
PRINT=0
SETTINGS=0

while [ $# -gt 0 ]; do
    case "$1" in
    --print) PRINT=1 ;;
    --settings) SETTINGS=1 ;;
    --source)
        shift
        if printf '%s' "${1:-}" | grep -qE '^[a-zA-Z0-9_.-]{1,15}$'; then
            case ", $SOURCES, " in
            *", \"$1\", "*) ;;
            *) SOURCES="$SOURCES, \"$1\"" ;;
            esac
        else
            echo "tenant-egress-limits: '${1:-}' is not a bridge name" >&2
            exit 2
        fi
        ;;
    *)
        echo "usage: tenant-egress-limits.sh [--print | --settings] [--source <bridge>]..." >&2
        exit 2
        ;;
    esac
    shift
done

# <name> <default>: a whole number from .env, or the default with a warning.
setting() {
    local value
    value=$(sed -n "s/^$1=//p" "$ENV_FILE" 2>/dev/null | tail -n 1 | tr -d "\"' \r")
    if [ -z "$value" ]; then
        echo "$2"
    elif printf '%s' "$value" | grep -qE '^[0-9]{1,7}$'; then
        echo $((value + 0))
    else
        echo "tenant-egress-limits: $1=$value is not a whole number; using $2" >&2
        echo "$2"
    fi
}

BLOCK_SMTP=$(setting EGRESS_BLOCK_SMTP 1)
CONNECTIONS=$(setting EGRESS_NEW_CONNECTIONS_PER_SECOND 100)
DESTINATIONS=$(setting EGRESS_NEW_DESTINATIONS_PER_MINUTE 20)
BURST=$(setting EGRESS_NEW_DESTINATIONS_BURST 60)
WEB_DESTINATIONS=$(setting EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE 200)
WEB_BURST=$(setting EGRESS_WEB_NEW_DESTINATIONS_BURST 600)
BLOCK_MINUTES=$(setting EGRESS_SCAN_BLOCK_MINUTES 30)
SCAN=1
{ [ "$DESTINATIONS" = 0 ] || [ "$BURST" = 0 ]; } && SCAN=0
WEB_SCAN=1
{ [ "$WEB_DESTINATIONS" = 0 ] || [ "$WEB_BURST" = 0 ]; } && WEB_SCAN=0
WEB_PORTS='{ 80, 443 }'

sets() {
    cat <<EOF
add set $TABLE sources { type ifname; }
add element $TABLE sources { $SOURCES }
EOF
    for family in 4 6; do
        type=ipv4_addr
        [ "$family" = 6 ] && type=ipv6_addr
        for seen in seen seenweb; do
            echo "add set $TABLE $seen$family { type $type . $type; flags dynamic, timeout; timeout 10m; size 262144; }"
        done
        for meter in newdst newdstweb connrate; do
            echo "add set $TABLE $meter$family { type $type; flags dynamic, timeout; timeout 1h; size 65536; }"
        done
        for mark in blocked flood smtp scan; do
            echo "add set $TABLE $mark$family { type $type; flags dynamic, timeout; timeout 10m; size 65536; }"
        done
    done
}

chains() {
    echo "add chain $TABLE forward { type filter hook forward priority filter - 10; policy accept; }"
    for chain in forward new_conn new_web smtp flood scan; do
        [ "$chain" = forward ] || echo "add chain $TABLE $chain"
        echo "flush chain $TABLE $chain"
    done
}

# Each family's statement is a rule of its own: a rule on an ip6 address
# stops at an IPv4 packet, and the next rule takes it.
rules() {
    echo "add rule $TABLE forward iifname @sources oifname != @sources ct state new jump new_conn"
    echo "add rule $TABLE new_conn ct status dnat return"
    if [ "$BLOCK_SMTP" != 0 ]; then
        echo "add rule $TABLE new_conn tcp dport 25 jump smtp"
    fi
    if { [ "$SCAN" = 1 ] || [ "$WEB_SCAN" = 1 ]; } && [ "$BLOCK_MINUTES" != 0 ]; then
        echo "add rule $TABLE new_conn ip saddr @blocked4 counter drop"
        echo "add rule $TABLE new_conn ip6 saddr @blocked6 counter drop"
    fi
    if [ "$CONNECTIONS" != 0 ]; then
        echo "add rule $TABLE new_conn update @connrate4 { ip saddr limit rate over $CONNECTIONS/second burst $((CONNECTIONS * 10)) packets } jump flood"
        echo "add rule $TABLE new_conn update @connrate6 { ip6 saddr limit rate over $CONNECTIONS/second burst $((CONNECTIONS * 10)) packets } jump flood"
    fi
    if [ "$WEB_SCAN" = 1 ]; then
        echo "add rule $TABLE new_conn tcp dport $WEB_PORTS goto new_web"
        destinations new_web seenweb newdstweb "$WEB_DESTINATIONS" "$WEB_BURST"
    elif [ "$SCAN" = 1 ]; then
        echo "add rule $TABLE new_conn tcp dport $WEB_PORTS return"
    fi
    if [ "$SCAN" = 1 ]; then
        destinations new_conn seen newdst "$DESTINATIONS" "$BURST"
    fi
    for mark in smtp flood; do
        echo "add rule $TABLE $mark update @${mark}4 { ip saddr }"
        echo "add rule $TABLE $mark update @${mark}6 { ip6 saddr }"
        echo "add rule $TABLE $mark limit rate 6/minute log prefix \"pa-egress $mark: \" level warn"
        echo "add rule $TABLE $mark counter drop"
    done
    if [ "$BLOCK_MINUTES" != 0 ]; then
        echo "add rule $TABLE scan add @blocked4 { ip saddr timeout $((BLOCK_MINUTES * 60))s }"
        echo "add rule $TABLE scan add @blocked6 { ip6 saddr timeout $((BLOCK_MINUTES * 60))s }"
        echo "add rule $TABLE scan limit rate 6/minute log prefix \"pa-egress scan, blocked ${BLOCK_MINUTES}m: \" level warn"
    else
        echo "add rule $TABLE scan update @scan4 { ip saddr }"
        echo "add rule $TABLE scan update @scan6 { ip6 saddr }"
        echo "add rule $TABLE scan limit rate 6/minute log prefix \"pa-egress scan: \" level warn"
    fi
    echo "add rule $TABLE scan counter drop"
}

# <chain> <seen set> <meter> <per minute> <burst>: a pair seen in the last 10
# minutes passes; a new one is counted, and one over the limit is a scan.
destinations() {
    echo "add rule $TABLE $1 ip saddr . ip daddr @${2}4 update @${2}4 { ip saddr . ip daddr } return"
    echo "add rule $TABLE $1 ip6 saddr . ip6 daddr @${2}6 update @${2}6 { ip6 saddr . ip6 daddr } return"
    echo "add rule $TABLE $1 update @${3}4 { ip saddr limit rate over $4/minute burst $5 packets } jump scan"
    echo "add rule $TABLE $1 update @${3}6 { ip6 saddr limit rate over $4/minute burst $5 packets } jump scan"
    echo "add rule $TABLE $1 add @${2}4 { ip saddr . ip daddr }"
    echo "add rule $TABLE $1 add @${2}6 { ip6 saddr . ip6 daddr }"
}

# <keep|replace>: one transaction; `replace` starts from an empty table.
transaction() {
    echo "add table $TABLE"
    if [ "$1" = replace ]; then
        echo "delete table $TABLE"
        echo "add table $TABLE"
    fi
    sets
    chains
    rules
}

if [ "$SETTINGS" = 1 ]; then
    echo "EGRESS_BLOCK_SMTP=$BLOCK_SMTP"
    echo "EGRESS_NEW_CONNECTIONS_PER_SECOND=$CONNECTIONS"
    echo "EGRESS_NEW_DESTINATIONS_PER_MINUTE=$DESTINATIONS"
    echo "EGRESS_NEW_DESTINATIONS_BURST=$BURST"
    echo "EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE=$WEB_DESTINATIONS"
    echo "EGRESS_WEB_NEW_DESTINATIONS_BURST=$WEB_BURST"
    echo "EGRESS_SCAN_BLOCK_MINUTES=$BLOCK_MINUTES"
    exit 0
fi

if [ "$PRINT" = 1 ]; then
    transaction keep
    exit 0
fi

if ! command -v nft >/dev/null 2>&1; then
    echo "tenant-egress-limits: nft is missing; outbound limits are not applied (install nftables)" >&2
    exit 0
fi

# Every set and chain this script makes. A table holding anything else was made
# by hand or by another version, and is replaced rather than added to.
known_objects() {
    transaction keep | awk '$1 == "add" && ($2 == "set" || $2 == "chain") { print $5 }' | LC_ALL=C sort -u
}
foreign_objects() {
    nft list table $TABLE 2>/dev/null | awk '$1 == "set" || $1 == "chain" { print $2 }' | LC_ALL=C sort -u |
        LC_ALL=C comm -23 - "$1"
}

scans() {
    if [ "$SCAN" = 0 ] && [ "$WEB_SCAN" = 0 ]; then
        echo unlimited
        return
    fi
    printf '%s, ' "$([ "$SCAN" = 0 ] && echo unlimited || echo "over $BURST then $DESTINATIONS/min new destinations")"
    printf 'on ports 80 and 443 %s, ' "$([ "$WEB_SCAN" = 0 ] && echo unlimited || echo "over $WEB_BURST then $WEB_DESTINATIONS/min")"
    if [ "$BLOCK_MINUTES" = 0 ]; then echo dropped; else echo "blocked ${BLOCK_MINUTES}m"; fi
}
summary="smtp $([ "$BLOCK_SMTP" = 0 ] && echo allowed || echo blocked), new connections $([ "$CONNECTIONS" = 0 ] && echo unlimited || echo "$CONNECTIONS/s"), scans $(scans)"
known=$(mktemp) || exit 1
trap 'rm -f "$known"' EXIT
known_objects >"$known"
if [ -z "$(foreign_objects "$known")" ] && transaction keep | nft -f - 2>/dev/null; then
    echo "tenant-egress-limits: applied ($summary)"
    exit 0
fi
if ! out=$(transaction replace | nft -f - 2>&1); then
    echo "tenant-egress-limits: could not apply the outbound limits: $(printf '%s\n' "$out" | grep . | head -n 3)" >&2
    exit 1
fi
echo "tenant-egress-limits: rebuilt, blocks and counters reset ($summary)"
