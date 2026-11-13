#!/bin/bash
#
# updater.sh against a fake curl serving get.panelalpha.com: nothing it or the
# bootstrap downloads outlives the run, and the installer's exit code is the
# updater's.
#
#   bash tests/scripts/updater.test.sh

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
# behind lands here. KEYS is the pinned key directory; none by default.
mkdir -p "$TMP/nokeys"
run() {
    local name="$1"
    shift
    mkdir -p "$TMP/run-$name" "$TMP/state-$name"
    rm -f "$TMP/args"
    PATH="$TMP/bin:$PATH" TMPDIR="$TMP/run-$name" FAKE_SITE="${SITE:-$TMP/site}" FAKE_STATE="$TMP/state-$name" \
        FAKE_ARGS="$TMP/args" PANELALPHA_RELEASE_KEY_DIR="${KEYS:-$TMP/nokeys}" \
        bash "$SCRIPT_DIR/updater.sh" "$@" >"$TMP/stdout-$name" 2>"$TMP/stderr-$name"
}
left() { find "$TMP/run-$1" -mindepth 1 | wc -l; }
ran() { [[ -e "$TMP/args" ]] && echo ran || echo absent; }

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

# The bootstrap's own fetch of get.sh would follow a redirect to plain http.
FAKE_CURL_FAIL_ONCE=get.sh run nogetsh -f --background
check "a failed get.sh download exits 1" "1" "$?"
check "and runs no installer" "absent" "$([[ -e "$TMP/args" ]] && echo ran || echo absent)"
check "and leaves nothing" "0" "$(left nogetsh)"

for flag in --package-host --version; do
    run "novalue$flag" -f "$flag"
    check "$flag without a value exits 1" "1" "$?"
    check "$flag without a value says so" "[ERROR] $flag requires a value" "$(cat "$TMP/stderr-novalue$flag")"
    check "$flag without a value runs no installer" "absent" "$([[ -e "$TMP/args" ]] && echo ran || echo absent)"
done

run packagehost -f --package-host example.com --background
check "--package-host with a value is accepted and dropped" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"

check "with no key pinned it says the download is not verified" "yes" \
    "$(grep -q 'No release-signing key is pinned' "$TMP/stderr-ok" && echo yes || echo no)"

# A signed site: the release key signs both files, as CI is to publish them.
keypair() { # <name>
    openssl ecparam -name prime256v1 -genkey -noout -out "$TMP/$1.key" 2>/dev/null
    openssl ec -in "$TMP/$1.key" -pubout -out "$TMP/$1.pem" 2>/dev/null
}
sign_site() { # <site dir> <key name>
    local page
    for page in engine get.sh; do
        openssl dgst -sha256 -sign "$TMP/$2.key" -out "$1/$page.sig" "$1/$page"
    done
}
keypair release
keypair next
keypair stranger
mkdir -p "$TMP/keys" "$TMP/rotating" "$TMP/signed"
cp "$TMP/release.pem" "$TMP/keys/"
cp "$TMP/release.pem" "$TMP/next.pem" "$TMP/rotating/"
cp "$TMP/site/engine" "$TMP/site/get.sh" "$TMP/signed/"
sign_site "$TMP/signed" release

SITE="$TMP/signed" KEYS="$TMP/keys" run signed -f --background
check "a signed update exits 0" "0" "$?"
check "a signed update runs the installer" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"
check "a signed update leaves nothing behind" "0" "$(left signed)"

cp "$TMP/signed/get.sh" "$TMP/signed/get.sh.orig"
echo 'touch "$FAKE_ARGS.forged"' >>"$TMP/signed/get.sh"
SITE="$TMP/signed" KEYS="$TMP/keys" run forged -f --background
check "a get.sh that does not match its signature exits 1" "1" "$?"
check "and runs no installer" "absent" "$([[ -e "$TMP/args" || -e "$TMP/args.forged" ]] && echo ran || echo absent)"
check "and says why" "yes" "$(grep -q 'does not match its signature' "$TMP/stderr-forged" && echo yes || echo no)"
check "and leaves nothing" "0" "$(left forged)"
mv "$TMP/signed/get.sh.orig" "$TMP/signed/get.sh"

rm "$TMP/signed/engine.sig"
SITE="$TMP/signed" KEYS="$TMP/keys" run unsigned -f --background
check "a missing signature exits 1 once a key is pinned" "1" "$?"
check "and runs no installer" "absent" "$([[ -e "$TMP/args" ]] && echo ran || echo absent)"

sign_site "$TMP/signed" stranger
SITE="$TMP/signed" KEYS="$TMP/keys" run stranger -f --background
check "a signature by a key that is not pinned exits 1" "1" "$?"
check "and runs no installer" "absent" "$([[ -e "$TMP/args" ]] && echo ran || echo absent)"

sign_site "$TMP/signed" next
SITE="$TMP/signed" KEYS="$TMP/rotating" run rotated -f --background
check "the next key pinned beside the current one verifies" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"

for flag in --help -h; do
    run "help$flag" -f --background "$flag"
    check "$flag exits 0" "0" "$?"
    check "$flag prints the usage" "yes" "$(grep -q '^Usage: bash updater.sh' "$TMP/stdout-help$flag" && echo yes || echo no)"
    check "$flag runs no installer" "absent" "$(ran)"
    check "$flag downloads nothing" "0" "$(left "help$flag")"
done
run helpfirst --version --help
check "--help wins over a flag missing its value" "0" "$?"

# Every fetch refuses anything but https, a redirect included.
cat >"$TMP/bin/curl" <<'EOF'
#!/bin/bash
[[ " $* " == *" --proto =https "* ]] || exit 1
url=${!#}
cp "$FAKE_SITE/${url##*/}" "${@: -2:1}"
EOF
run https -f --background
check "every download is https-only" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
