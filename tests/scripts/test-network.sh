# shellcheck shell=bash
#
# Every IP address a shell test uses, sourced by the *.test.sh scripts. A test
# writes no address literal (core's TestNetworkLiteralsTest enforces it):
# scenario addresses are hosts of the TEST_NET_* networks in
# tests/test-network.env, which an environment variable of the same name
# overrides, and addresses fixed by a standard or by the engine are the TN_*
# values below, as in Tests\Support\TestNetwork.
#
#   . "$(dirname "${BASH_SOURCE[0]}")/test-network.sh"
#   "$(tn_host PUBLIC 7)"      the 7th address of TEST_NET_PUBLIC
#   "$(tn_net PRIVATE)"        TEST_NET_PRIVATE as a CIDR

TN_CONFIG="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/test-network.env"

TN_ANY=0.0.0.0
TN_ANY_CIDR=0.0.0.0/0
TN_THIS_NETWORK=0.0.0.1
TN_LOOPBACK=127.0.0.1
TN_LOOPBACK_CIDR=127.0.0.0/8
TN_HOSTNAME_LOOPBACK=127.0.1.1
TN_LINK_LOCAL_CIDR=169.254.0.0/16
TN_METADATA=169.254.169.254
TN_PRIVATE_10_CIDR=10.0.0.0/8
TN_PRIVATE_172_CIDR=172.16.0.0/12
TN_PRIVATE_192_CIDR=192.168.0.0/16
TN_PRIVATE_10=10.0.0.1
TN_PRIVATE_172=172.16.0.1
TN_PRIVATE_192=192.168.0.1
TN_CGNAT_CIDR=100.64.0.0/10
TN_CGNAT=100.64.0.1
TN_DOCUMENTATION_1=192.0.2.10
TN_DOCUMENTATION_2=198.51.100.10
TN_DOCUMENTATION_3=203.0.113.10
TN_IETF_PROTOCOL=192.0.0.8
TN_BENCHMARK=198.18.0.1
TN_MULTICAST=224.0.0.1
TN_FUTURE_USE=240.0.0.1
TN_MASK_24=255.255.255.0
TN_DOCKER_DNS=127.0.0.11
TN_DOCKER_BRIDGE_GATEWAY=172.17.0.1
TN_DOCKER_BRIDGE_CIDR=172.17.0.0/16
TN_RESOLVED_STUB=127.0.0.53
TN_ENGINE_DNS_PROXY=127.0.0.54
TN_HEALTH_PROBE_CLIENT=192.0.2.1
TN_TENANT_NETWORK_CIDR=10.200.0.0/16

tn_net() { # <name> -- TEST_NET_<name> as a CIDR: the environment's, else the config file's
    local variable="TEST_NET_$1" value
    value="${!variable:-}"
    [ -n "$value" ] || value=$(sed -n "s/^${variable}=//p" "$TN_CONFIG" | head -n 1)
    if ! [[ "$value" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$ ]]; then
        echo "tn_net: ${variable} must be an IPv4 network; define it in ${TN_CONFIG}" >&2
        return 1
    fi
    printf '%s\n' "$value"
}

tn_host() { # <name> <host> -- the <host>th address of TEST_NET_<name>
    local net base bits
    net=$(tn_net "$1") || return 1
    base=${net%/*}
    bits=${net#*/}
    if (( $2 < 0 || $2 >= (1 << (32 - bits)) )); then
        echo "tn_host: host $2 is outside ${net}" >&2
        return 1
    fi
    tn_address $(( $(tn_number "$base") + $2 ))
}

tn_add() { # <address> <delta> -- the address <delta> places after <address>
    tn_address $(( $(tn_number "$1") + $2 ))
}

tn_prefix() { # <address> <bits> -- the network of <bits> that holds <address>
    local mask=0
    (( $2 == 0 )) || mask=$(( (0xFFFFFFFF << (32 - $2)) & 0xFFFFFFFF ))
    printf '%s/%s\n' "$(tn_address $(( $(tn_number "$1") & mask )))" "$2"
}

tn_number() { # <address>
    local IFS=. a b c d
    read -r a b c d <<<"$1"
    printf '%s\n' $(( (a << 24) | (b << 16) | (c << 8) | d ))
}

tn_address() { # <number>
    printf '%d.%d.%d.%d\n' $(( ($1 >> 24) & 255 )) $(( ($1 >> 16) & 255 )) $(( ($1 >> 8) & 255 )) $(( $1 & 255 ))
}
