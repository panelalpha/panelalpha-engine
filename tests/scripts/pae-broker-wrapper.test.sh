#!/bin/bash
#
# The pae-broker wrapper runs the action in the root broker's container, passing --env values by name only.
#
#   bash tests/scripts/pae-broker-wrapper.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/bin"
printf '#!/bin/bash\nprintf "%%s\\n" "$*"\n' >"$TMP/bin/docker"
chmod +x "$TMP/bin/docker"

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

broker() {
    PATH="$TMP/bin:$PATH" bash "$SCRIPT_DIR/pae-broker.sh" "$@" </dev/null
}

check "an action runs in the broker's container without a terminal" \
    "compose exec -T root-broker pa-broker --host host.applyTenantNetworkFirewall --" \
    "$(broker --host host.applyTenantNetworkFirewall --)"

check "the values reach pa-broker as given" \
    "compose exec -T root-broker pa-broker files.read -- /home/alice/a b" \
    "$(broker files.read -- /home/alice/a b)"

check "--env passes the variable by name, its value never on the command line" \
    "compose exec -T -e LICENSE_KEY -e OTHER root-broker pa-broker --host --env LICENSE_KEY --env OTHER system.license -- x" \
    "$(LICENSE_KEY=secret broker --host --env LICENSE_KEY --env OTHER system.license -- x)"

check "an --env that is not a variable name is not forwarded" \
    "compose exec -T root-broker pa-broker --env LICENSE_KEY=secret system.license -- x" \
    "$(broker --env LICENSE_KEY=secret system.license -- x)"

check "--env among the values is a value, not an option" \
    "compose exec -T root-broker pa-broker files.read -- --env FOO" \
    "$(broker files.read -- --env FOO)"

check "--pty without a terminal keeps -T" \
    "compose exec -T root-broker pa-broker --host --pty host.shell -- alice" \
    "$(broker --host --pty host.shell -- alice)"

if command -v script >/dev/null 2>&1; then
    check "--pty on a terminal lets compose allocate one" \
        "compose exec root-broker pa-broker --pty host.shell -- alice" \
        "$(PATH="$TMP/bin:$PATH" script -qec "bash $SCRIPT_DIR/pae-broker.sh --pty host.shell -- alice" /dev/null | tr -d '\r')"
fi

printf '\n%s passed, %s failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
