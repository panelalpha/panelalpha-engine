#!/bin/bash
#
# The pae wrapper passes the migrate secrets into the core container by name.
#
#   bash tests/scripts/pae-wrapper.test.sh

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

command="$TMP/docker"

check "the migrate secrets reach artisan" \
    "compose exec -e WORDPRESS_MIGRATE_PASSWORD=secret -e WORDPRESS_MIGRATE_SOURCE_TOKEN=token core php artisan wordpress:migrate https://example.com admin" \
    "$(PATH="$TMP/bin:$PATH" WORDPRESS_MIGRATE_PASSWORD=secret WORDPRESS_MIGRATE_SOURCE_TOKEN=token bash "$SCRIPT_DIR/pae.sh" wordpress:migrate https://example.com admin)"

check "an unset secret is empty, not inherited" \
    "compose exec -e WORDPRESS_MIGRATE_PASSWORD= -e WORDPRESS_MIGRATE_SOURCE_TOKEN= core php artisan wordpress:user:list shop" \
    "$(env -u WORDPRESS_MIGRATE_PASSWORD -u WORDPRESS_MIGRATE_SOURCE_TOKEN PATH="$TMP/bin:$PATH" bash "$SCRIPT_DIR/pae.sh" wordpress:user:list shop)"

rm -f "$command"

printf '\n%s passed, %s failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
