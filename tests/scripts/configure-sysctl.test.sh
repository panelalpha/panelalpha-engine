#!/bin/bash
#
# What configure-sysctl.sh writes, checked without root and without touching a
# running kernel.
#
#   bash tests/scripts/configure-sysctl.test.sh
#
# The regression this guards: the file it used to write was
#
#     kernel.panic = 3
#     vm.panic_on_oom = 1
#
# and that pair rebooted hosts mid-deploy. `vm.panic_on_oom = 0` is the fix,
# and it has to stay *written* rather than omitted -- `sysctl --system` applies
# the values files name and never resets a setting a file stopped mentioning,
# so a host that already had 1 would keep it until its next reboot.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

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

# A setting's effective value, comments and blank lines ignored. Reads every
# occurrence and returns the last, which is what the kernel applies.
value_of() {
    local file="$1" key="$2"
    sed -e 's/#.*//' "$file" \
        | grep -E "^[[:space:]]*${key//./\\.}[[:space:]]*=" \
        | tail -1 \
        | sed -E 's/.*=[[:space:]]*//' \
        | tr -d '[:space:]'
}

out="$TMP/99-panelalpha.conf"

echo "=== the file it writes ==="
PANELALPHA_SYSCTL_FILE="$out" PANELALPHA_SYSCTL_NO_APPLY=1 \
    bash "$SCRIPT_DIR/configure-sysctl.sh" >"$TMP/stdout" 2>&1
rc=$?
check "exits 0" "0" "$rc"
check "wrote the file" "yes" "$([[ -f "$out" ]] && echo yes || echo no)"

echo "=== the one that mattered ==="
check "vm.panic_on_oom is written" "yes" \
    "$(grep -qE '^[[:space:]]*vm\.panic_on_oom[[:space:]]*=' "$out" && echo yes || echo no)"
check "vm.panic_on_oom is 0" "0" "$(value_of "$out" vm.panic_on_oom)"

echo "=== the one that is fine ==="
check "kernel.panic is 3" "3" "$(value_of "$out" kernel.panic)"

echo "=== it is not applied when asked not to be ==="
check "no sysctl --system" "no" "$(grep -q 'sysctl --system' "$TMP/stdout" && echo yes || echo no)"

echo "=== rewriting is idempotent ==="
PANELALPHA_SYSCTL_FILE="$out" PANELALPHA_SYSCTL_NO_APPLY=1 \
    bash "$SCRIPT_DIR/configure-sysctl.sh" >/dev/null 2>&1
check "vm.panic_on_oom still 0" "0" "$(value_of "$out" vm.panic_on_oom)"
check "kernel.panic still 3" "3" "$(value_of "$out" kernel.panic)"

echo "=== a host that already had 1 gets it reset, not left alone ==="
# The failure mode an omission would cause: the old value survives because
# nothing contradicts it. Confirm the file actively states 0.
if grep -qE '^[[:space:]]*vm\.panic_on_oom[[:space:]]*=[[:space:]]*0[[:space:]]*$' "$out"; then
    printf '  ok    an existing 1 is contradicted by an explicit 0\n'
    PASS=$((PASS + 1))
else
    printf '  FAIL  an existing 1 is contradicted by an explicit 0\n'
    FAIL=$((FAIL + 1))
fi

echo
echo "passed $PASS, failed $FAIL"
[[ "$FAIL" -eq 0 ]]
