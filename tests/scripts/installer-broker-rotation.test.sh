#!/bin/bash
#
# installer.sh's update pauses the old broker's logrotate before the copy and resumes it after.
#
#   bash tests/scripts/installer-broker-rotation.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

for fn in broker_rotation pause_broker_rotation resume_broker_rotation; do
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
# Runs the in-container script against $W/run/service and $W/proc, with logged s6 and sleep stubs.
docker() {
    [ -f "$W/broker-down" ] && return 1
    local script=${*: -1}
    mkdir -p "$W/bin"
    cat >"$W/bin/s6-svc" <<EOF
#!/bin/sh
echo "s6-svc \$1 \$(basename "\$(cd "\$2" && pwd)")" >>$W/calls
EOF
    printf '#!/bin/sh\ncat %s\n' "$W/pid" >"$W/bin/s6-svstat"
    cat >"$W/bin/sleep" <<EOF
#!/bin/sh
echo sleep >>$W/calls
left=\$(cat $W/passing)
[ "\$left" -gt 1 ] && echo \$((left - 1)) >$W/passing || echo sleep >$W/proc/41/comm
EOF
    chmod +x "$W/bin/"*
    script=${script//\/run\/service/$W/run/service}
    PATH="$W/bin:$PATH" sh -c "${script//\/proc/$W/proc}"
}
calls() { tr '\n' ' ' <"$W/calls" | sed 's/ $//'; }
state() { # <pid> <comm of the run process> <sleeps before the pass ends>
    echo "$1" >"$W/pid"
    echo "$2" >"$W/proc/41/comm"
    echo "$3" >"$W/passing"
    : >"$W/calls"
}

mkdir -p "$W/run/service/logrotate" "$W/proc/41"

state 41 sleep 0
BROKER_ROTATION_PAUSED=0
pause_broker_rotation
check "a broker between passes starts no other" "s6-svc -O logrotate" "$(calls)"
check "and is resumed later" "1" "$BROKER_ROTATION_PAUSED"

state 41 sh 3
BROKER_ROTATION_PAUSED=0
pause_broker_rotation
check "a pass under way is waited for" "s6-svc -O logrotate sleep sleep sleep" "$(calls)"

state -1 sh 3
BROKER_ROTATION_PAUSED=0
pause_broker_rotation
check "a rotation already down is not waited for" "s6-svc -O logrotate" "$(calls)"

state 41 sleep 0
resume_broker_rotation
check "and started again" "s6-svc -u logrotate" "$(calls)"
: >"$W/calls"
resume_broker_rotation
check "a second start does nothing" "" "$(calls)"

touch "$W/broker-down"
BROKER_ROTATION_PAUSED=0
pause_broker_rotation
check "no running broker: the update goes on" "0" "$?"
check "and nothing is started later" "0" "$BROKER_ROTATION_PAUSED"

grep -q 'declare -F resume_broker_rotation >/dev/null || resume_broker_rotation' "$SCRIPT_DIR/installer.sh"
check "an update that stops early starts it again" "0" "$?"
check "the new stack starts it again" "start_engine_stack check_engine_stack resume_broker_rotation" \
    "$(grep -A2 -x ' *start_engine_stack' "$SCRIPT_DIR/installer.sh" | tr -d ' ' | tr '\n' ' ' | sed 's/ $//')"
check "before the copy" "pause_broker_rotation unzip_panelalpha_engine" \
    "$(grep -oE '^ *(pause_broker_rotation|unzip_panelalpha_engine)$' "$SCRIPT_DIR/installer.sh" | tr -d ' ' | tr '\n' ' ' | sed 's/ $//')"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
