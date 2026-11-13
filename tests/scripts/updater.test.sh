#!/bin/bash
#
# updater.sh against a fake curl serving get.panelalpha.com and a fake git
# serving the target release: nothing it or the bootstrap downloads outlives the
# run, the installer's exit code is the updater's, and only a newer release than
# the running one reaches the installer.
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
echo "${PANELALPHA_ALLOW_DOWNGRADE:-} ${PANELALPHA_REINSTALL:-}" >"$FAKE_ARGS.env"
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

# A clone of the target release holds a core/config/system.php declaring
# $FAKE_TARGET_VERSION, at commit $FAKE_TARGET_COMMIT; with the version empty
# the clone fails, with the commit empty rev-parse does. Every call is logged.
cat >"$TMP/bin/git" <<'EOF'
#!/bin/bash
echo "$*" >>"$FAKE_STATE/git"
if [[ " $* " == *" rev-parse "* ]]; then
    [[ -n "${FAKE_TARGET_COMMIT:-}" ]] || exit 128
    echo "$FAKE_TARGET_COMMIT"
    exit 0
fi
[[ "$1" == clone ]] || exit 0
[[ -n "${FAKE_TARGET_VERSION:-}" ]] || exit 128
dest=${!#}
mkdir -p "$dest/core/config"
printf "<?php\n\nreturn [\n    'version' => '%s',\n];\n" "$FAKE_TARGET_VERSION" >"$dest/core/config/system.php"
EOF
chmod +x "$TMP/bin/git"

# The running engine, installed from commit aaaaaaaa.
RUNNING_COMMIT=aaaaaaaa11112222333344445555666677778888
mkdir -p "$TMP/engine/core/config"
printf "<?php\n\nreturn [\n    'version' => '2.3.0',\n];\n" >"$TMP/engine/core/config/system.php"
echo "$RUNNING_COMMIT" >"$TMP/engine/version"

# mktemp honours TMPDIR, so anything the updater or the bootstrap leaves
# behind lands here. KEYS is the pinned key directory; none by default.
mkdir -p "$TMP/nokeys"
run() {
    local name="$1"
    shift
    mkdir -p "$TMP/run-$name" "$TMP/state-$name" "$TMP/logs-$name"
    rm -f "$TMP/args" "$TMP/args.env"
    PATH="$TMP/bin:$PATH" TMPDIR="$TMP/run-$name" FAKE_SITE="${SITE:-$TMP/site}" FAKE_STATE="$TMP/state-$name" \
        FAKE_ARGS="$TMP/args" PANELALPHA_RELEASE_KEY_DIR="${KEYS:-$TMP/nokeys}" \
        FAKE_TARGET_VERSION="${TARGET-2.4.0}" FAKE_TARGET_COMMIT="${COMMIT-bbbbbbbb99990000aaaabbbbccccddddeeeeffff}" \
        PANELALPHA_ENGINE_DIR="$TMP/engine" \
        PANELALPHA_ENGINE_UPDATE_LOGS="$TMP/logs-$name" \
        bash "$SCRIPT_DIR/updater.sh" "$@" >"$TMP/stdout-$name" 2>"$TMP/stderr-$name"
}
left() { find "$TMP/run-$1" -mindepth 1 | wc -l; }
ran() { [[ -e "$TMP/args" ]] && echo ran || echo absent; }
record() { cat "$TMP/logs-$1/latest/$2" 2>/dev/null; }
records() { find "$TMP/logs-$1" -mindepth 1 | wc -l; }

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
    check "$flag clones nothing" "absent" "$([[ -e "$TMP/state-help$flag/git" ]] && echo ran || echo absent)"
    check "$flag downloads nothing" "0" "$(left "help$flag")"
    check "$flag leaves no record" "0" "$(records "help$flag")"
done
run helpfirst --version --help
check "--help wins over a flag missing its value" "0" "$?"

run target -f --background --version 2.4.0
check "the target is read from the ref asked for" "yes" "$(grep -q -- '--branch 2.4.0' "$TMP/state-target/git" && echo yes || echo no)"
check "the default ref is the installer's" "yes" "$(grep -q -- '--branch main' "$TMP/state-fail/git" && echo yes || echo no)"
check "a newer release reaches the installer" "--no-tui --background --engine-version 2.4.0" "$(cat "$TMP/args" 2>/dev/null)"
check "and its installer is not told to allow anything" "0 0" "$(cat "$TMP/args.env" 2>/dev/null)"

TARGET=2.2.0 run older -f --background
check "an older release exits 1" "1" "$?"
check "and runs no installer" "absent" "$(ran)"
check "and downloads nothing" "0" "$(left older)"
check "and says so" "yes" "$(grep -q 'Refusing to install 2.2.0 (main) over the running 2.3.0' "$TMP/stderr-older" && echo yes || echo no)"
check "its record is refused" "refused" "$(record older result)"
check "with the running version" "2.3.0" "$(record older from_version)"
check "and the target's" "2.2.0" "$(record older to_version)"
check "and an exit code" "1" "$(record older exit_code)"
check "and the reason" "yes" "$(record older reason | grep -q 'older release. Nothing was changed.' && echo yes || echo no)"

TARGET=2.2.0 run downgrade -f --background --allow-downgrade
check "--allow-downgrade installs an older release" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"
check "and tells its installer through the environment" "1 0" "$(cat "$TMP/args.env" 2>/dev/null)"
check "and leaves the record to the installer" "0" "$(records downgrade)"

TARGET=v2.3.0 COMMIT=$RUNNING_COMMIT run same -f --background
check "the running commit of the running release exits 0" "0" "$?"
check "and runs no installer" "absent" "$(ran)"
check "and says it is up to date" "yes" "$(grep -q 'Already up to date: 2.3.0 (aaaaaaaa) is running and main is the same commit' "$TMP/stdout-same" && echo yes || echo no)"
check "its record is up_to_date" "up_to_date" "$(record same result)"
check "with exit code 0" "0" "$(record same exit_code)"
check "and both commits" "aaaaaaaa aaaaaaaa" "$(record same from_commit) $(record same to_commit)"

TARGET=2.3.0 run within -f --background
check "another commit of the running release reaches the installer" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"
check "and says it updates within the release" "yes" "$(grep -q 'Updating within 2.3.0: aaaaaaaa → bbbbbbbb (main)' "$TMP/stdout-within" && echo yes || echo no)"
check "and needs no --reinstall" "0 0" "$(cat "$TMP/args.env" 2>/dev/null)"

TARGET=2.3.0 COMMIT='' run nocommit -f --background
check "a target commit that cannot be read goes on" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"
mv "$TMP/engine/version" "$TMP/engine/version.off"
TARGET=2.3.0 COMMIT=$RUNNING_COMMIT run norunning -f --background
check "an unknown running commit goes on" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"
mv "$TMP/engine/version.off" "$TMP/engine/version"

TARGET=2.2.0 COMMIT=$RUNNING_COMMIT run oldersame -f --background
check "an older release is refused whatever its commit" "1" "$?"

TARGET=2.3.0 COMMIT=$RUNNING_COMMIT run reinstall -f --background --reinstall
check "--reinstall installs the running release again" "--no-tui --background" "$(cat "$TMP/args" 2>/dev/null)"
check "and tells its installer" "0 1" "$(cat "$TMP/args.env" 2>/dev/null)"

TARGET='' run unreadable -f --background
check "a target whose version cannot be read exits 1" "1" "$?"
check "and runs no installer" "absent" "$(ran)"
check "its record says why" "yes" "$(record unreadable reason | grep -q 'Could not read the release version at main' && echo yes || echo no)"
check "and that it failed" "failed" "$(record unreadable result)"

run check --check
check "--check exits 0" "0" "$?"
check "--check says an update is available" "yes" "$(grep -q '^An update is available.' "$TMP/stdout-check" && echo yes || echo no)"
check "--check names both versions and commits" "yes" "$(grep -q '^Running:   2.3.0 (aaaaaaaa)' "$TMP/stdout-check" && grep -q '^Available: 2.4.0 (bbbbbbbb, main)' "$TMP/stdout-check" && echo yes || echo no)"
check "--check runs no installer" "absent" "$(ran)"
check "--check leaves no record" "0" "$(records check)"
TARGET=2.3.0 COMMIT=$RUNNING_COMMIT run checksame --check
check "--check on the running commit says up to date" "yes" "$(grep -q '^Already up to date.' "$TMP/stdout-checksame" && echo yes || echo no)"
TARGET=2.3.0 run checkwithin --check
check "--check on another commit of the running release says so" "yes" "$(grep -q '^An update within the release is available.' "$TMP/stdout-checkwithin" && echo yes || echo no)"
TARGET=2.2.0 run checkolder --check
check "--check on an older release says it is refused" "yes" "$(grep -q 'older than the running one' "$TMP/stdout-checkolder" && echo yes || echo no)"

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
