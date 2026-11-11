#!/bin/bash
#
#   bash tests/scripts/refresh-webserver-image.test.sh

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
    restart: always
    mem_limit: 3G
YAML
}

ship_ulimits() { # ship_ulimits <variant file>: what the shipped variants carry after `restart:`
    sed -i 's/^    restart: always$/    restart: always\n    # open files for every site\n    ulimits:\n      nofile:\n        soft: 65536\n        hard: 524288/' "$1"
}

nofile_soft() { awk '/^    ulimits:/ { u = 1 } u && /soft:/ { print $2; exit }' "$1"; }

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

ship_ulimits "$d/docker-compose.yml-nginx-proxy"
out=$(bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d")
check "an old copy takes the shipped open-files limit" "65536" "$(nofile_soft "$d/docker-compose.yml-webserver")"
check "the block lands after restart:, its comment with it" "    restart: always|    # open files for every site|    ulimits:" \
    "$(grep -A2 '^    restart:' "$d/docker-compose.yml-webserver" | paste -sd'|')"
check "the operator's other edits stay, with the limit" "    mem_limit: 5G" "$(grep mem_limit "$d/docker-compose.yml-webserver")"
check "it says it added the limit" "Webserver open-files limit added from docker-compose.yml-nginx-proxy" "$out"

out=$(bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d")
check "a current copy is left alone, silently" "" "$out"
check "the limit is added once" "1" "$(grep -c '^    ulimits:' "$d/docker-compose.yml-webserver")"

variant "$d/docker-compose.yml-webserver" nginx-proxy ghcr.io/panelalpha/app-nginx:20261004
sed -i 's/^    restart: always$/    restart: always\n    ulimits:\n      nofile:\n        soft: 4096\n        hard: 8192/' "$d/docker-compose.yml-webserver"
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d" >/dev/null
check "an operator's own limit stays" "4096" "$(nofile_soft "$d/docker-compose.yml-webserver")"

variant "$d/docker-compose.yml-webserver" apache ghcr.io/panelalpha/engine-apache:20250101
bash "$SCRIPT_DIR/refresh-webserver-image.sh" "$d" >/dev/null
check "the variant comes from the copy's label" "ghcr.io/panelalpha/engine-apache:20260526" "$(image_of "$d/docker-compose.yml-webserver")"
check "a variant shipping no limit adds none" "0" "$(grep -c '^    ulimits:' "$d/docker-compose.yml-webserver")"

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
