#!/bin/bash
#
# installer.sh gives a fresh install, and an update on a host with no store, a local backup store.
#
#   bash tests/scripts/installer-default-backup-store.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
W="$(mktemp -d)"
trap 'rm -rf "$W"' EXIT

sed -n '/^ensure_default_backup_store() {/,/^}$/p' "$SCRIPT_DIR/installer.sh" >"$W/funcs.sh"
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

echo_warning() { echo "warn:$1"; }
# Records the command the installer would run in core's container.
docker() {
    printf '%s\n' "$*" >"$W/calls"
    return "${DOCKER_EXIT:-0}"
}
calls() { cat "$W/calls"; }

ensure_default_backup_store
check "the engine creates the store through artisan" \
    "compose -f /opt/panelalpha/shared-hosting/docker-compose.yml exec -T core php artisan backup:container:ensure-default" \
    "$(calls)"

DOCKER_EXIT=1
out=$(ensure_default_backup_store)
check "a failure is a warning, not a failed install" "yes" "$(case "$out" in warn:*) echo yes ;; *) echo no ;; esac)"
check "and names the command that retries" "yes" "$(case "$out" in *'pae backup:container:ensure-default'*) echo yes ;; *) echo no ;; esac)"
DOCKER_EXIT=0

migrate_line=$(grep -n 'php artisan migrate --force' "$SCRIPT_DIR/installer.sh" | head -1 | cut -d: -f1)
ensure_line=$(grep -n '^    ensure_default_backup_store$' "$SCRIPT_DIR/installer.sh" | head -1 | cut -d: -f1)
check "installer.sh creates it after the migrations" "yes" "$([ -n "$ensure_line" ] && [ "$ensure_line" -gt "$migrate_line" ] && echo yes)"

check "the source deploy does the same" "yes" \
    "$(grep -q 'core_artisan backup:container:ensure-default' "$SCRIPT_DIR/bootstrap-from-source.sh" && echo yes)"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
