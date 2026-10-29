#!/bin/bash
#
# installer.sh's host git check against a fake git: traditional PHP hosting
# pins its host-side git with http.curloptResolve, which git before 2.37
# ignores, so the installer warns about such a git and about nothing else.
#
#   bash tests/scripts/installer-host-git.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# installer.sh installs an engine when sourced, so cut out the one function.
sed -n "/^warn_old_host_git() {/,/^}\$/p" "$SCRIPT_DIR/installer.sh" >"$TMP/funcs.sh"
# shellcheck source=/dev/null
source "$TMP/funcs.sh"

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

echo_warning() { echo "warning:$1"; }
FAKE_GIT=''
git() { [ -n "$FAKE_GIT" ] || return 127; printf '%s\n' "$FAKE_GIT"; }

# Under the installer's own set -e: the check must never end the install.
run() { (set -e; warn_old_host_git; echo survived); }

FAKE_GIT='git version 2.34.1'
check "Ubuntu 22.04's git 2.34 is warned about" \
    "warning:git 2.34 is older than 2.37: on traditional PHP hosting the engine refuses a git pull from any host name not in EGRESS_ALLOW_HOSTS until git on this server is upgraded.
survived" "$(run)"

FAKE_GIT='git version 2.37.0'
check "git 2.37 says nothing" "survived" "$(run)"

FAKE_GIT='git version 2.39.5'
check "Debian 12's git 2.39 says nothing" "survived" "$(run)"

FAKE_GIT=''
check "no git says nothing" "survived" "$(run)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
