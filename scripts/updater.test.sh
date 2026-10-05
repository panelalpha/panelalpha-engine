#!/bin/bash
#
# updater.sh against a fake curl: the downloaded installer never outlives the
# run, and the installer's exit code is the updater's.
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

# Fake curl: writes an installer that records its arguments and exits with
# $FAKE_INSTALLER_EXIT, or fails the download when FAKE_CURL_FAIL is set.
mkdir -p "$TMP/bin"
cat >"$TMP/bin/curl" <<'EOF'
#!/bin/bash
[[ -n "${FAKE_CURL_FAIL:-}" ]] && exit 22
out=""
while [[ $# -gt 0 ]]; do
    [[ "$1" == "-o" ]] && out="$2"
    shift
done
printf 'echo "$@" > "%s"\nexit %s\n' "$FAKE_ARGS" "${FAKE_INSTALLER_EXIT:-0}" >"$out"
EOF
chmod +x "$TMP/bin/curl"

# mktemp honours TMPDIR, so anything the updater leaves behind lands here.
run() {
    local dir="$TMP/run-$1"
    shift
    mkdir -p "$dir"
    PATH="$TMP/bin:$PATH" TMPDIR="$dir" FAKE_ARGS="$TMP/args" \
        bash "$SCRIPT_DIR/updater.sh" "$@" >/dev/null 2>&1
}
left() { find "$TMP/run-$1" -mindepth 1 | wc -l; }

FAKE_INSTALLER_EXIT=0 run ok -f --background --version 2.1.0
check "a successful update exits 0" "0" "$?"
check "a successful update leaves no installer" "0" "$(left ok)"
check "the installer got the forwarded flags" "--no-tui --background --engine-version 2.1.0" "$(cat "$TMP/args")"

FAKE_INSTALLER_EXIT=3 run fail -f --background
check "a failed update keeps the installer's exit code" "3" "$?"
check "a failed update leaves no installer" "0" "$(left fail)"

FAKE_CURL_FAIL=1 run nofetch -f
check "a failed download exits 1" "1" "$?"
check "a failed download leaves nothing" "0" "$(left nofetch)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
