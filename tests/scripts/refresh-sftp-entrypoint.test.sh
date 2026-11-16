#!/bin/bash
#
# refresh-sftp-entrypoint.sh against a fake docker: a service that runs a bind-mounted entry
# point is recreated when that file changed since its container started, and not otherwise.
#
#   bash tests/scripts/refresh-sftp-entrypoint.test.sh

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

engine="$TMP/engine"
entryfile="$engine/config/sftp/entrypoint.sh"
stampfile="$engine/config/sftp/.entrypoint.sha256"
mkdir -p "$engine/config/sftp" "$TMP/bin"
printf 'services:\n  sftp:\n    image: ghcr.io/panelalpha/engine-sftp:v2.3.0\n' >"$engine/docker-compose.yml"

# Answers the two compose calls the script makes and records each one with the directory it ran in.
cat >"$TMP/bin/docker" <<'FAKE'
#!/bin/bash
printf '%s in %s\n' "$*" "$PWD" >>"$CALLS"
case "$*" in
"compose ps -qa sftp")
    if [[ "${PS_FAILS:-0}" = 1 ]]; then exit 1; fi
    printf '%s' "${CONTAINER:-}"
    ;;
"compose up -d --force-recreate --no-deps sftp")
    if [[ "${UP_FAILS:-0}" = 1 ]]; then exit 1; fi
    ;;
*) exit 1 ;;
esac
exit 0
FAKE
chmod +x "$TMP/bin/docker"
export CALLS="$TMP/calls" CONTAINER='' PS_FAILS=0 UP_FAILS=0
export PATH="$TMP/bin:$PATH"

entry() { printf '#!/bin/bash\numask 0%s\n' "$1" >"$entryfile"; }
hash_of() { printf '#!/bin/bash\numask 0%s\n' "$1" | sha256sum | cut -d' ' -f1; }
stamp() { cat "$stampfile" 2>/dev/null; }
entry_hash() { sha256sum "$entryfile" | cut -d' ' -f1; }
run() {
    : >"$CALLS"
    out=$(bash "$SCRIPT_DIR/refresh-sftp-entrypoint.sh" "$engine" 2>&1)
    code=$?
}
calls() { paste -sd'|' "$CALLS" 2>/dev/null; }
last_call() { tail -n1 "$CALLS" 2>/dev/null; }
recreated() { echo "SFTP container recreated: its entry point changed"; }
ps_call() { echo "compose ps -qa sftp in $engine"; }
up_call() { echo "compose up -d --force-recreate --no-deps sftp in $engine"; }

entry a11
run
check "a host with no sftp container only asks compose about it" "$(ps_call)" "$(calls)"
check "it says nothing" "" "$out"
check "it records the entry point the container will start with" "$(hash_of a11)" "$(stamp)"

run
check "the second run asks nothing" "" "$(calls)"

export CONTAINER='fe46compose-sftp-1'
run
check "a container already started from this entry point is left alone" "" "$(calls)"

entry b22
run
check "a changed entry point recreates the service" "$(ps_call)|$(up_call)" "$(calls)"
check "it says why" "$(recreated)" "$out"
check "the new entry point is the one recorded" "$(hash_of b22)" "$(stamp)"

run
check "and the run after it asks nothing" "" "$(calls)"

touch "$entryfile"
run
check "a new mtime with the same content recreates nothing" "" "$(calls)"

entry c33
run
check "changed content recreates again" "$(up_call)" "$(last_call)"

entry d44
export PS_FAILS=1
run
check "a host whose compose does not know the service changes nothing" "0" "$code"
check "and recreates nothing" "$(ps_call)" "$(calls)"
check "the entry point is recorded for the container the stack start makes" "$(hash_of d44)" "$(stamp)"
export PS_FAILS=0

entry e55
export UP_FAILS=1
run
check "a recreate that fails fails the step" "1" "$code"
check "so it does not record an entry point no container started with" "$(hash_of d44)" "$(stamp)"
export UP_FAILS=0

run
check "the retried update recreates the service" "$(up_call)" "$(last_call)"
check "and records it" "$(entry_hash)" "$(stamp)"

rm "$entryfile"
run
check "a host without the file does nothing" "0" "$code"
check "and asks compose nothing" "" "$(calls)"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
