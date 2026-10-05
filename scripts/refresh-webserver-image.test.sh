#!/bin/bash
#
#   bash scripts/refresh-webserver-image.test.sh
#
# The regression this guards: docker-compose.yml-webserver is copied once with
# `cp -n`, so a bumped webserver image tag never reached an installed host.

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

variant() { # variant <file> <slug> <image>
    cat >"$1" <<YAML
services:
  sites-http:
    build:
      context: ./dockerfiles
    image: $3
    pull_policy: missing
    labels:
      - com.panelalpha.webserver=$2
    mem_limit: 3G
YAML
}

image_of() { sed -n 's/^    image: *//p' "$1"; }

d="$TMP/engine"
mkdir -p "$d"
variant "$d/docker-compose.yml-nginx-proxy" nginx-proxy ghcr.io/panelalpha/app-nginx:20261004
variant "$d/docker-compose.yml-apache" apache ghcr.io/panelalpha/engine-apache:20260526

variant "$d/docker-compose.yml-webserver" nginx-proxy ghcr.io/panelalpha/app-nginx:20260525
sed -i 's/mem_limit: 3G/mem_limit: 5G/' "$d/docker-compose.yml-webserver"
out=$(bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d")
check "an old copy takes the shipped tag" "ghcr.io/panelalpha/app-nginx:20261004" "$(image_of "$d/docker-compose.yml-webserver")"
check "the operator's other edits stay" "    mem_limit: 5G" "$(grep mem_limit "$d/docker-compose.yml-webserver")"
check "it says what it changed" "Webserver image ghcr.io/panelalpha/app-nginx:20260525 -> ghcr.io/panelalpha/app-nginx:20261004" "$out"

out=$(bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d")
check "a current copy is left alone, silently" "" "$out"

variant "$d/docker-compose.yml-webserver" apache ghcr.io/panelalpha/engine-apache:20250101
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d" >/dev/null
check "the variant comes from the copy's label" "ghcr.io/panelalpha/engine-apache:20260526" "$(image_of "$d/docker-compose.yml-webserver")"

variant "$d/docker-compose.yml-webserver" nginx-proxy registry.example.com/own-nginx:1
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d" >/dev/null
check "an image from another repository stays" "registry.example.com/own-nginx:1" "$(image_of "$d/docker-compose.yml-webserver")"

variant "$d/docker-compose.yml-webserver" litespeed ghcr.io/panelalpha/engine-litespeed:20250101
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d" >/dev/null
check "a variant with no shipped file stays" "ghcr.io/panelalpha/engine-litespeed:20250101" "$(image_of "$d/docker-compose.yml-webserver")"

rm "$d/docker-compose.yml-webserver"
ln -s docker-compose.yml-nginx-proxy "$d/docker-compose.yml-webserver"
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d" >/dev/null
check "a symlink is not written through" "ghcr.io/panelalpha/app-nginx:20261004" "$(image_of "$d/docker-compose.yml-nginx-proxy")"
check "a symlink stays a symlink" "yes" "$([ -L "$d/docker-compose.yml-webserver" ] && echo yes)"

rm "$d/docker-compose.yml-webserver"
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d"
check "no copy at all exits 0" "0" "$?"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
