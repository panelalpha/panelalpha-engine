#!/bin/bash
#
# installer.sh's update stops core's scheduler and queue workers from the copy until migrate ran.
#
#   bash tests/scripts/installer-core-background.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

for fn in core_background pause_core_background resume_core_background; do
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
# Runs the in-container script against $W/run/service, with s6-svc logged.
docker() {
    [ -f "$W/core-down" ] && return 1
    local script=${*: -1}
    mkdir -p "$W/bin"
    printf '#!/bin/sh\necho "$1 ${2##*/}" >>%s\n' "$W/calls" >"$W/bin/s6-svc"
    chmod +x "$W/bin/s6-svc"
    PATH="$W/bin:$PATH" sh -c "${script//\/run\/service/$W/run/service}"
}
calls() { tr '\n' ' ' <"$W/calls" | sed 's/ $//'; }

mkdir -p "$W/run/service/scheduler" "$W/run/service/queue-1" "$W/run/service/queue-2" "$W/run/service/php-fpm"
: >"$W/calls"
CORE_BACKGROUND_PAUSED=0
pause_core_background
check "the scheduler and every worker are stopped" "-d scheduler -d queue-1 -d queue-2" "$(calls)"
: >"$W/calls"
resume_core_background
check "and started again" "-u scheduler -u queue-1 -u queue-2" "$(calls)"
: >"$W/calls"
resume_core_background
check "a second start does nothing" "" "$(calls)"

touch "$W/core-down"
CORE_BACKGROUND_PAUSED=0
pause_core_background
check "no running core: the update goes on" "0" "$?"
check "and nothing is started later" "0" "$CORE_BACKGROUND_PAUSED"

grep -q 'declare -F resume_core_background >/dev/null || resume_core_background' "$SCRIPT_DIR/installer.sh"
check "an update that stops early starts them again" "0" "$?"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
