#!/bin/bash
#
# installer.sh restarts Docker after the firewall only when Docker's iptables chains are gone.
#
#   bash tests/scripts/installer-docker-restart.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

sed -n "/^restart_docker_without_its_chains() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >"$W/funcs.sh"
# shellcheck source=/dev/null
source "$W/funcs.sh"

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

echo_info() { :; }
# $W/chains lists the chains that exist, as "<table> <chain>".
iptables() {
    local table=filter chain=
    while [ $# -gt 0 ]; do
        case $1 in
        -t) table=$2 && shift ;;
        -L) chain=$2 && shift ;;
        esac
        shift
    done
    grep -qx "$table $chain" "$W/chains"
}
systemctl() { :; }
service() { echo "$*" >>"$W/restarts"; }

restarts() {
    : >"$W/restarts"
    printf '%s\n' "$@" >"$W/chains"
    restart_docker_without_its_chains
    wc -l <"$W/restarts" | tr -d ' '
}

check "both chains there: no restart" "0" "$(restarts 'filter DOCKER' 'nat DOCKER')"
check "nat chain flushed: restart" "1" "$(restarts 'filter DOCKER')"
check "filter chain flushed: restart" "1" "$(restarts 'nat DOCKER')"
check "everything flushed: restart" "1" "$(restarts '')"
check "the restart is of docker" "docker restart" "$(restarts '' >/dev/null; cat "$W/restarts")"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
