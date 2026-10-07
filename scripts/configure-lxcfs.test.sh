#!/bin/bash
#
# The systemd drop-ins configure-lxcfs.sh writes, checked without root.
#
#   bash scripts/configure-lxcfs.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
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

run() {
    PANELALPHA_SYSTEMD_DIR="$TMP" PANELALPHA_LXCFS_NO_APPLY=1 bash "$SCRIPT_DIR/configure-lxcfs.sh" >"$TMP/stdout" 2>&1
}

lxcfs="$TMP/lxcfs.service.d/panelalpha.conf"
docker="$TMP/docker.service.d/panelalpha-lxcfs.conf"

echo "=== the drop-ins it writes ==="
run
check "exits 0" "0" "$?"
check "lxcfs drop-in written" "yes" "$([[ -f "$lxcfs" ]] && echo yes || echo no)"
check "docker drop-in written" "yes" "$([[ -f "$docker" ]] && echo yes || echo no)"

echo "=== lxcfs runs with per-cgroup loadavg and CPU quota ==="
# The first ExecStart= clears the package's; without it systemd refuses two.
check "package ExecStart cleared" "ExecStart=" "$(grep -m1 '^ExecStart=' "$lxcfs")"
check "loadavg and cfs enabled" "ExecStart=/usr/bin/lxcfs -l --enable-cfs /var/lib/lxcfs" "$(grep '^ExecStart=/' "$lxcfs")"
check "dollar escaped for systemd" "yes" "$(grep -q 'ExecStartPost=.*\$\$(seq' "$lxcfs" && echo yes || echo no)"
check "waits for meminfo" "yes" "$(grep -q 'ExecStartPost=.*/var/lib/lxcfs/proc/meminfo' "$lxcfs" && echo yes || echo no)"

echo "=== docker starts after lxcfs ==="
check "After" "After=lxcfs.service" "$(grep '^After=' "$docker")"
check "Wants" "Wants=lxcfs.service" "$(grep '^Wants=' "$docker")"

echo "=== nothing applied when asked not to be ==="
check "no systemctl or apt" "no" "$(grep -qE 'systemctl|apt-get|Could not' "$TMP/stdout" && echo yes || echo no)"

echo "=== rewriting is idempotent ==="
before="$(cat "$lxcfs" "$docker")"
run
check "same files" "$before" "$(cat "$lxcfs" "$docker")"
check "no temp files left" "0" "$(find "$TMP" -name '*.tmp' | wc -l | tr -d ' ')"

echo
echo "passed $PASS, failed $FAIL"
[[ "$FAIL" -eq 0 ]]
