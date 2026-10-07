#!/bin/bash
#
# updater.sh against a fake curl serving get.panelalpha.com: nothing it or the
# bootstrap downloads outlives the run, and the installer's exit code is the
# updater's.
#
#   bash scripts/updater.test.sh

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

# /engine is the bootstrap as get.panelalpha.com serves it: it runs a get.sh
# found beside it, or downloads one to a temp file and execs it. /get.sh stands
# in for the installer: it records its arguments and exits $FAKE_INSTALLER_EXIT.
mkdir -p "$TMP/site" "$TMP/bin"
cat >"$TMP/site/engine" <<'EOF'
#!/bin/sh
set -eu
export PANELALPHA_ENTRY='engine'
export PANELALPHA_GET_BASE='https://get.panelalpha.com'
GET_BASE=$PANELALPHA_GET_BASE
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" 2>/dev/null && pwd || true)
if [ -n "$DIR" ] && [ -f "$DIR/get.sh" ]; then
    exec sh "$DIR/get.sh" "$@"
fi
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT INT TERM
curl -fsSL --max-time 60 -o "$TMP" "${GET_BASE}/get.sh" || exit 1
exec sh "$TMP" "$@"
EOF
cat >"$TMP/site/get.sh" <<'EOF'
echo "$@" >"$FAKE_ARGS"
exit "${FAKE_INSTALLER_EXIT:-0}"
EOF

# FAKE_CURL_FAIL=<page> fails every request for it, FAKE_CURL_FAIL_ONCE=<page>
# only the first.
cat >"$TMP/bin/curl" <<'EOF'
#!/bin/bash
out="" url=""
while [[ $# -gt 0 ]]; do
    case "$1" in
    -o) out="$2"; shift ;;
    http*) url="$1" ;;
    esac
    shift
done
page="${url##*/}"
[[ "$page" == "${FAKE_CURL_FAIL:-}" ]] && exit 22
if [[ "$page" == "${FAKE_CURL_FAIL_ONCE:-}" && ! -e "$FAKE_STATE/failed-$page" ]]; then
    touch "$FAKE_STATE/failed-$page"
    exit 22
fi
cp "$FAKE_SITE/$page" "$out"
EOF
chmod +x "$TMP/bin/curl"

# mktemp honours TMPDIR, so anything the updater or the bootstrap leaves
# behind lands here.
run() {
    local name="$1"
    shift
    mkdir -p "$TMP/run-$name" "$TMP/state-$name"
    rm -f "$TMP/args"
    PATH="$TMP/bin:$PATH" TMPDIR="$TMP/run-$name" FAKE_SITE="$TMP/site" FAKE_STATE="$TMP/state-$name" \
        FAKE_ARGS="$TMP/args" bash "$SCRIPT_DIR/updater.sh" "$@" >/dev/null 2>&1
}
left() { find "$TMP/run-$1" -mindepth 1 | wc -l; }

FAKE_INSTALLER_EXIT=0 run ok -f --background --version 2.1.0
check "a successful update exits 0" "0" "$?"
check "a successful update leaves nothing behind" "0" "$(left ok)"
check "the installer got the forwarded flags" "--no-tui --background --engine-version 2.1.0" "$(cat "$TMP/args")"

FAKE_INSTALLER_EXIT=3 run fail -f --background
check "a failed update keeps the installer's exit code" "3" "$?"
check "a failed update leaves nothing behind" "0" "$(left fail)"

FAKE_CURL_FAIL=engine run nofetch -f
check "a failed download exits 1" "1" "$?"
check "a failed download leaves nothing" "0" "$(left nofetch)"

# The bootstrap used to sit straight in $TMPDIR, where it ran any get.sh it found.
mkdir -p "$TMP/run-planted"
printf 'touch "%s"\n' "$TMP/planted-ran" >"$TMP/run-planted/get.sh"
run planted -f --background
check "a get.sh left in TMPDIR is not run" "absent" "$([[ -e "$TMP/planted-ran" ]] && echo ran || echo absent)"
check "the downloaded installer ran instead" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"

FAKE_CURL_FAIL_ONCE=get.sh run nogetsh -f --background
check "without get.sh beside it the bootstrap fetches its own" "0" "$?"
check "and the update still runs" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
