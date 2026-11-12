#!/bin/bash
#
# installer.sh's copy while its updater runs: that updater finishes on its own text.
#
#   bash tests/scripts/installer-running-updater.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

for fn in replace_running_updaters unzip_panelalpha_engine; do
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

remove_dropped_engine_files() { :; }
# installer-root-scripts.test.sh runs it.
hand_engine_files_to_root() { :; }
echo_error() {
    echo "error:$1"
    exit 101
}

INSTALL_DIR="$W/install"
PANELALPHA_DIR="$W/opt"
ENGINE="$PANELALPHA_DIR/shared-hosting"
mkdir -p "$INSTALL_DIR/src/scripts" "$ENGINE/scripts"

# The running updater waits on a fifo, then prints its last line.
mkfifo "$W/go"
for f in updater.sh scripts/updater.sh; do
    printf '#!/bin/bash\nread -r _ <"%s"\necho "old %s done"\n' "$W/go" "$f" >"$ENGINE/$f"
    {
        printf '#!/bin/bash\n'
        for _ in $(seq 1 40); do printf '# a longer release\n'; done
        printf 'echo "new %s"\n' "$f"
    } >"$INSTALL_DIR/src/$f"
    chmod 0755 "$INSTALL_DIR/src/$f"
done
echo 'new tree' >"$INSTALL_DIR/src/scripts/other.sh"

bash "$ENGINE/scripts/updater.sh" >"$W/out" 2>&1 &
running=$!
sleep 0.5
unzip_panelalpha_engine
echo >"$W/go"
wait "$running"
code=$?

check "the running updater exits 0" "0" "$code"
check "the running updater ends on its own last line" "old scripts/updater.sh done" "$(cat "$W/out")"
for f in updater.sh scripts/updater.sh; do
    check "$f is the new release's" "$(cat "$INSTALL_DIR/src/$f")" "$(cat "$ENGINE/$f")"
    check "$f keeps its mode" "755" "$(stat -c %a "$ENGINE/$f")"
    check "no $f.new is left" "no" "$([ -e "$ENGINE/$f.new" ] && echo yes || echo no)"
done
check "the rest of the tree is copied" "new tree" "$(cat "$ENGINE/scripts/other.sh")"

rm -f "$ENGINE/updater.sh" "$ENGINE/scripts/updater.sh"
unzip_panelalpha_engine
check "a fresh install gets the updaters by the copy" "$(cat "$INSTALL_DIR/src/scripts/updater.sh")" "$(cat "$ENGINE/scripts/updater.sh")"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
