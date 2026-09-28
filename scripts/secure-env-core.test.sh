#!/bin/bash
#
# What secure-env-core.sh leaves behind. Checks the owner only when run as
# root, so run it both ways:
#
#   bash scripts/secure-env-core.test.sh
#   docker run --rm -v "$PWD/scripts:/s:ro" debian:trixie-slim bash /s/secure-env-core.test.sh
#
# The regression this guards (engine#48 item 23): the installer created
# .env-core with `cp -n` under the default umask, so APP_KEY and the core
# database password were world-readable at 0644.

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

engine="$TMP/shared-hosting"
mkdir -p "$engine/core"
env_core="$engine/.env-core"
backup="$engine/core/.env.pae-backup"

(umask 022 && echo "APP_KEY=base64:secret" >"$env_core" && echo "APP_KEY=base64:old" >"$backup")
check "fixture starts world-readable" "644" "$(stat -c %a "$env_core")"

bash "$SCRIPT_DIR/secure-env-core.sh" "$env_core" 2>/dev/null
status=$?
check "exits 0" "0" "$status"

if [ "$(id -u)" -eq 0 ]; then
    check ".env-core is 0640" "640" "$(stat -c %a "$env_core")"
    check ".env-core is root:33" "0:33" "$(stat -c %u:%g "$env_core")"
    check "the pae backup is 0640" "640" "$(stat -c %a "$backup")"
    check "the pae backup is root:33" "0:33" "$(stat -c %u:%g "$backup")"

    # The point of gid 33: www-data can still read it, anyone else cannot.
    if id www-data >/dev/null 2>&1 && id nobody >/dev/null 2>&1; then
        chmod 755 "$TMP" "$engine"
        check "www-data can read it" "APP_KEY=base64:secret" "$(su -s /bin/sh www-data -c "cat '$env_core'" 2>/dev/null)"
        (umask 022 && echo control >"$engine/control")
        check "another user can read a 0644 file (control)" "control" "$(su -s /bin/sh nobody -c "cat '$engine/control'" 2>/dev/null)"
        check "another user cannot read .env-core" "" "$(su -s /bin/sh nobody -c "cat '$env_core'" 2>/dev/null)"
    fi

    bash "$SCRIPT_DIR/secure-env-core.sh" "$env_core" 2>/dev/null
    check "a second run changes nothing" "640 0:33" "$(stat -c '%a %u:%g' "$env_core")"
else
    # Without root the group cannot become 33, and dropping the world bit on
    # its own would lock core's www-data out: the file is left alone.
    check "without root the mode is left alone" "644" "$(stat -c %a "$env_core")"
fi

bash "$SCRIPT_DIR/secure-env-core.sh" "$TMP/absent/.env-core" 2>/dev/null
check "a missing file is not an error" "0" "$?"

echo
echo "secure-env-core: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
