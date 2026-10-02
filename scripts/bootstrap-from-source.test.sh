#!/bin/bash
# Checks when bootstrap-from-source.sh reinstalls Composer dependencies: the
# upload never carries core/vendor, so a changed composer.lock must trigger an
# install on a host that already has one.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

eval "$(sed -n '/^COMPOSER_LOCK_STAMP=/p; /^composer_needed() {/,/^}/p' "$SCRIPT_DIR/bootstrap-from-source.sh")"
cd "$WORK_DIR" && mkdir -p core

failures=0
expect() { # expect <label> <yes|no>
    local got=no
    composer_needed && got=yes
    if [ "$got" = "$2" ]; then
        echo "PASS: $1"
    else
        echo "FAIL: $1 -- expected $2, got $got"
        failures=$((failures + 1))
    fi
}

FORCE_COMPOSER=0
echo '{"content-hash": "a"}' >core/composer.lock
expect "no vendor/ installs" yes

mkdir core/vendor
sha256sum core/composer.lock | cut -d' ' -f1 >core/vendor/.pa-composer-lock
expect "vendor/ built from this lock is kept" no

echo '{"content-hash": "b"}' >core/composer.lock
expect "a changed composer.lock installs" yes

rm core/vendor/.pa-composer-lock
expect "vendor/ from before the stamp existed installs" yes

sha256sum core/composer.lock | cut -d' ' -f1 >core/vendor/.pa-composer-lock
FORCE_COMPOSER=1
expect "--composer always installs" yes

[ "$failures" -eq 0 ] && echo "All passed." || echo "${failures} failed."
exit "$failures"
