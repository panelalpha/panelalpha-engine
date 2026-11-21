#!/bin/bash
#
# The installers re-apply the tenant firewall via the root broker; only an empty network gets the script.
#
#   bash tests/scripts/installer-tenant-firewall.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

PASS=0
FAIL=0
check() {
    local what="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        printf '  ok    %s\n' "$what"
        PASS=$((PASS + 1))
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$what" "$expected" "$actual"
        FAIL=$((FAIL + 1))
    fi
}

# Stub state in $W: members, broker (its id), ask (its exit code) and stdin.
docker() {
    case "$1 $2" in
    "network inspect")
        [ -f "$W/members" ] || { echo "Error: No such network: pash-tenants" >&2; return 1; }
        cat "$W/members"
        return 0
        ;;
    esac
    case " $* " in
    *" ps "*) cat "$W/broker" 2>/dev/null ;;
    *" exec "*)
        local request="$*"
        echo "${request#*exec }" >>"$W/asked"
        cat >"$W/stdin"
        return "$(cat "$W/ask")"
        ;;
    esac
}
bash() {
    echo "$* MTU=${DOCKER_NETWORK_MTU-unset}" >>"$W/ran"
    [ -f "$W/fails" ] || return 0
    cat "$W/fails" >&2
    return 1
}
echo_info() { echo "$1" >>"$W/said"; }
warn() { echo "$1" >>"$W/said"; }

state() { # <members|none> <broker id|none> <broker's exit code>
    rm -f "$W/members" "$W/broker" "$W/asked" "$W/ran" "$W/said" "$W/stdin" "$W/fails"
    [ "$1" = none ] || echo "$1" >"$W/members"
    [ "$2" = none ] || echo "$2" >"$W/broker"
    echo "$3" >"$W/ask"
}
ran() { cat "$W/ran" 2>/dev/null; }
asked() { cat "$W/asked" 2>/dev/null; }
said() { cat "$W/said" 2>/dev/null; }

ASK='-T root-broker pa-broker --host host.applyTenantNetworkFirewall --'

for installer in installer.sh bootstrap-from-source.sh; do
    echo "$installer"
    sed -n '/^apply_tenant_network_firewall() {/,/^}$/p' "$SCRIPT_DIR/$installer" >"$W/funcs.sh"
    # shellcheck source=/dev/null
    source "$W/funcs.sh"
    case $installer in
    installer.sh) FIREWALL=/opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh ;;
    *) FIREWALL=scripts/tenant-network-firewall.sh ;;
    esac
    DOCKER_NETWORK_MTU=1400

    state none none 0
    apply_tenant_network_firewall
    check "a fresh install makes the network and closes it" "$FIREWALL --create --restart-docker MTU=1400" "$(ran)"
    check "and asks no broker" "" "$(asked)"

    state 0 none 0
    apply_tenant_network_firewall
    check "a network nothing is on yet is applied as it is" "$FIREWALL --create --restart-docker MTU=1400" "$(ran)"

    state 0 b1 0
    apply_tenant_network_firewall
    check "even with a broker running" "$FIREWALL --create --restart-docker MTU=1400" "$(ran)"

    if [ "$installer" = installer.sh ]; then
        check "a network made: no reason kept" "" "${TENANT_NETWORK_ERROR-unset}"

        state none none 0
        printf 'tenant-network-firewall: no free /16 for pash-tenants; set TENANT_NETWORK_PREFIX\n\n' >"$W/fails"
        apply_tenant_network_firewall 2>"$W/stderr"
        check "a network not made: the script's last line is the reason monitoring gets" \
            "no free /16 for pash-tenants; set TENANT_NETWORK_PREFIX" "$TENANT_NETWORK_ERROR"
        check "and the operator still sees it" "1" "$(grep -c 'no free /16' "$W/stderr")"

        state none none 0
        printf 'Error response from daemon: invalid pool request\n' >"$W/fails"
        apply_tenant_network_firewall 2>/dev/null
        check "Docker's own refusal is kept as it is" "Error response from daemon: invalid pool request" "$TENANT_NETWORK_ERROR"

        state none none 0
        apply_tenant_network_firewall
        check "made on a retry: the old reason is dropped" "" "$TENANT_NETWORK_ERROR"
    fi

    state 5 b1 0
    apply_tenant_network_firewall
    check "accounts on it: the script binds none of their ports, so it is not run" "" "$(ran)"
    check "the running broker applies it, with every port, in its own container: core is not needed" "$ASK" "$(asked)"
    check "on no stdin of the installer's" "" "$(cat "$W/stdin")"
    check "nothing to say" "" "$(said)"

    state 5 b1 127
    apply_tenant_network_firewall </dev/null
    check "a broker from a release without pa-broker: not run without the ports" "" "$(ran)"
    check "the rules in force are left to the new broker, which applies them as it starts" "1" \
        "$(said | grep -c 'until the new root broker applies it')"

    state 5 b1 1
    apply_tenant_network_firewall
    check "a broker that does not answer: still not run without the ports" "" "$(ran)"
    check "it was asked" "$ASK" "$(asked)"
    check "and the new broker is said to apply it" "1" "$(said | grep -c 'until the new root broker applies it')"

    state 5 none 0
    apply_tenant_network_firewall
    check "no broker yet (an update from a release before it): not run" "" "$(ran)"
    check "and nothing is asked of a broker that is not there" "" "$(asked)"
    check "the rules in force are left to the new broker" "1" "$(said | grep -c 'until the new root broker applies it')"

    others=$(awk '/^apply_tenant_network_firewall\(\) \{/ { inside = 1 } !inside { print } /^}$/ { inside = 0 }' "$SCRIPT_DIR/$installer" |
        grep 'tenant-network-firewall\.sh' | grep -v -e '--install-units' -e '^ *#' -e 'tenant-network-firewall messages')
    check "nothing else runs the script without the ports" "" "$others"
    check "each apply goes through it" "2" "$(grep -c '^ *apply_tenant_network_firewall$' "$SCRIPT_DIR/$installer")"
done

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
