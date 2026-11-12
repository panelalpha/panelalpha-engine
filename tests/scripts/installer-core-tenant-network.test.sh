#!/bin/bash
#
# installer.sh's update stops a pre-broker core's tenant firewall loop before the copy.
#
#   bash tests/scripts/installer-core-tenant-network.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

for fn in core_tenant_network stop_core_tenant_network start_core_tenant_network; do
    sed -n "/^${fn}() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >>"$W/funcs.sh"
done
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
# Runs the in-container script against $W/run/service, with logged s6-svc and kill.
docker() {
    [ -f "$W/core-down" ] && return 1
    local script=${*: -1}
    mkdir -p "$W/bin"
    cat >"$W/bin/s6-svc" <<EOF
#!/bin/sh
echo "s6-svc \$1 \$(basename "\$(cd "\$2" && pwd)")" >>$W/calls
EOF
    printf '#!/bin/sh\ncat %s\n' "$W/pid" >"$W/bin/s6-svstat"
    chmod +x "$W/bin/"*
    PATH="$W/bin:$PATH" sh -c "kill() { echo \"kill \$*\" >>$W/calls; }
${script//\/run\/service/$W/run/service}"
}
calls() { tr '\n' ' ' <"$W/calls" | sed 's/ $//'; }
state() { # <pid of the service, -1 while down>
    echo "$1" >"$W/pid"
    : >"$W/calls"
}

mkdir -p "$W/run/service/tenant-network"

state 41
CORE_TENANT_NETWORK_STOPPED=0
stop_core_tenant_network
check "a running loop is stopped, its whole process group with it" "s6-svc -d tenant-network kill -TERM -41" "$(calls)"
check "and is started again if the update stops early" "1" "$CORE_TENANT_NETWORK_STOPPED"

state 41
start_core_tenant_network
check "started again" "s6-svc -u tenant-network" "$(calls)"
: >"$W/calls"
start_core_tenant_network
check "a second start does nothing" "" "$(calls)"

state -1
CORE_TENANT_NETWORK_STOPPED=0
stop_core_tenant_network
check "a loop already down: nothing left to signal" "s6-svc -d tenant-network" "$(calls)"

rm -rf "$W/run/service/tenant-network"
state 41
CORE_TENANT_NETWORK_STOPPED=0
stop_core_tenant_network
check "a core without the loop: the update goes on" "0" "$?"
check "nothing is stopped" "" "$(calls)"
check "and nothing is started later" "0" "$CORE_TENANT_NETWORK_STOPPED"

touch "$W/core-down"
CORE_TENANT_NETWORK_STOPPED=0
stop_core_tenant_network
check "no running core: the update goes on" "0" "$?"
check "and nothing is started later" "0" "$CORE_TENANT_NETWORK_STOPPED"

grep -q 'declare -F start_core_tenant_network >/dev/null || start_core_tenant_network' "$SCRIPT_DIR/installer.sh"
check "an update that stops early starts it again" "0" "$?"
check "before the copy, with the scheduler and workers" "pause_core_background stop_core_tenant_network unzip_panelalpha_engine" \
    "$(grep -oE '^ *(pause_core_background|stop_core_tenant_network|unzip_panelalpha_engine)$' "$SCRIPT_DIR/installer.sh" | tr -d ' ' | tr '\n' ' ' | sed 's/ $//')"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
