#!/bin/bash
#
#   bash scripts/seed-modsecurity.test.sh
#
# The regression this guards: config/modsecurity was seeded with `cp -Rn`, so
# a ruleset file another build left on a host stayed there unnoticed, and a
# shipped rule the operator had switched off came back enabled on every update.

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

has() { [ -e "$1" ] && echo yes || echo no; }

shipped_tree() { # <engine dir>
    local t="$1/templates/config/modsecurity"
    mkdir -p "$t/rulesets/crs/rules" "$t/rulesets/wp/rules" "$1/scripts"
    echo 'SecRuleEngine DetectionOnly' >"$t/main.conf"
    echo 'SecAction "id:900000"' >"$t/rulesets/crs/setup.conf"
    echo 'SecRule ARGS "a" "id:920100"' >"$t/rulesets/crs/rules/REQUEST-920.conf"
    echo 'SecRule ARGS "b" "id:930100"' >"$t/rulesets/crs/rules/REQUEST-930.conf"
    echo 'php' >"$t/rulesets/crs/rules/php-errors.data"
    echo 'SecRule ARGS "c" "id:1000100"' >"$t/rulesets/wp/rules/WP.conf"
    cp "$SCRIPT_DIR/seed-modsecurity.sh" "$1/scripts/"
}

seed() { bash "$1/scripts/seed-modsecurity.sh" "$1"; }

d="$TMP/engine"
shipped_tree "$d"
c="$d/config/modsecurity"
r="$c/rulesets"

out=$(seed "$d")
check "a fresh host gets every shipped file" "" "$(diff -r "$d/templates/config/modsecurity" "$c")"
check "a fresh host reports nothing" "" "$out"
check "the log directory exists" "yes" "$(has "$d/logs/modsecurity")"

echo 'SecRuleEngine On' >"$c/main.conf"
echo 'SecAction "id:900000,setvar:tx.paranoia=2"' >"$r/crs/setup.conf"
echo 'SecRule ARGS "x" "id:899100"' >"$r/crs/rules/REQUEST-899-OTHER-BUILD.conf"
mv "$r/crs/rules/REQUEST-930.conf" "$r/crs/rules/REQUEST-930.conf.disabled"
mkdir -p "$r/custom"
echo 'SecRule ARGS "y" "id:1100001"' >"$r/custom/custom.conf"
mkdir -p "$r/legacy/rules"
echo 'old' >"$r/legacy/rules/OLD.conf"
rm "$r/wp/rules/WP.conf"
out=$(seed "$d")

expected="ModSecurity ruleset files on this host that differ from the shipped ones (left as they are):
  changed:     $r/crs/setup.conf
  not shipped: $r/crs/rules/REQUEST-899-OTHER-BUILD.conf
  not shipped: $r/legacy/rules/OLD.conf"
check "a changed and an unshipped ruleset file are reported" "$expected" "$out"
check "the changed file keeps the operator's edit" 'SecAction "id:900000,setvar:tx.paranoia=2"' "$(cat "$r/crs/setup.conf")"
check "the unshipped file stays" "yes" "$(has "$r/crs/rules/REQUEST-899-OTHER-BUILD.conf")"
check "a disabled shipped rule stays disabled" "no" "$(has "$r/crs/rules/REQUEST-930.conf")"
check "the disabled copy stays" "yes" "$(has "$r/crs/rules/REQUEST-930.conf.disabled")"
check "a deleted shipped file is seeded again" "yes" "$(has "$r/wp/rules/WP.conf")"
check "the generated main.conf is left alone" "SecRuleEngine On" "$(cat "$c/main.conf")"

echo 'SecRule ARGS "z" "id:930100"' >"$r/crs/rules/REQUEST-930.conf.disabled"
out=$(seed "$d")
check "a disabled rule that differs is reported by its disabled name" "  changed:     $r/crs/rules/REQUEST-930.conf.disabled" "$(grep 930 <<<"$out")"

e="$TMP/bootstrap"
mkdir -p "$e/templates"
cp -a "$SCRIPT_DIR/../templates/config" "$SCRIPT_DIR/../templates/webserver-config" "$e/templates/"
mkdir -p "$e/scripts" "$e/config/modsecurity/rulesets/owasp-crs/rules"
cp "$SCRIPT_DIR/seed-modsecurity.sh" "$e/scripts/"
echo 'stray' >"$e/config/modsecurity/rulesets/owasp-crs/rules/REQUEST-899-STRAY.conf"
cp "$e/templates/config/modsecurity/rulesets/owasp-crs/rules/REQUEST-913-SCANNER-DETECTION.conf" \
    "$e/config/modsecurity/rulesets/owasp-crs/rules/REQUEST-913-SCANNER-DETECTION.conf.disabled"
eval "$(sed -n '/^seed_config_dirs() {/,/^}/p' "$SCRIPT_DIR/bootstrap-from-source.sh")"
out=$(cd "$e" && seed_config_dirs 2>&1)
check "bootstrap seeds the shipped rulesets" "" "$(diff -r "$e/templates/config/modsecurity/rulesets/owasp-crs/rules" "$e/config/modsecurity/rulesets/owasp-crs/rules" | grep -v 'REQUEST-899-STRAY\|REQUEST-913')"
check "bootstrap keeps a disabled shipped rule disabled" "no" "$(has "$e/config/modsecurity/rulesets/owasp-crs/rules/REQUEST-913-SCANNER-DETECTION.conf")"
check "bootstrap reports the stray file" "  not shipped: $e/config/modsecurity/rulesets/owasp-crs/rules/REQUEST-899-STRAY.conf" "$(grep 'not shipped' <<<"$out")"

check "the installer seeds through the same script" "1" "$(grep -c 'scripts/seed-modsecurity.sh /opt/panelalpha/shared-hosting$' "$SCRIPT_DIR/installer.sh")"
check "no seeding path copies the rulesets by itself" "" "$(grep -l 'templates/config/modsecurity/\.' "$SCRIPT_DIR/installer.sh" "$SCRIPT_DIR/bootstrap-from-source.sh")"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
