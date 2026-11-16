#!/bin/bash
#
#   bash tests/scripts/seed-modsecurity.test.sh
#
# The regression this guards: config/modsecurity was seeded with `cp -Rn`, so
# a ruleset file another build left on a host stayed there unnoticed, and a
# shipped rule the operator had switched off came back enabled on every update.
# And: no shipped rule file was ever overwritten, so a host kept a rule's old,
# wider version after every update that fixed it.

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
  (without the engine's history this run cannot tell an earlier release's copy from an edit, so none was replaced)
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
echo older >>"$e/config/modsecurity/rulesets/owasp-crs/rules/php-errors.data"
out=$(cd "$e" && seed_config_dirs 2>&1)
check "a source tree has no history to tell a shipped copy by, so bootstrap replaces nothing and says so" "older|1" \
    "$(tail -n1 "$e/config/modsecurity/rulesets/owasp-crs/rules/php-errors.data")|$(grep -c 'none was replaced' <<<"$out")"

# With the versions the engine shipped: a host copy that is one of them is brought
# up to date, anything else stays and is listed.
blob() { printf '%s\n' "$1" | GIT_DIR=/nonexistent git hash-object --no-filters --stdin; }
d="$TMP/versions"
shipped_tree "$d"
c="$d/config/modsecurity"
r="$c/rulesets"
seed "$d" >/dev/null
{
    printf '%s crs/rules/REQUEST-920.conf\n' "$(blob 'SecRule ARGS "a" "id:920100"')" "$(blob 'SecRule ARGS "old a" "id:920100"')"
    printf '%s crs/rules/REQUEST-930.conf\n' "$(blob 'SecRule ARGS "old b" "id:930100"')"
    printf '%s crs/setup.conf\n' "$(blob 'SecAction "id:900000,old"')"
    printf '%s wp/rules/WP.conf\n' "$(blob 'SecRule ARGS "old c" "id:1000100"')"
} >"$TMP/shipped"
echo 'SecRule ARGS "old a" "id:920100"' >"$r/crs/rules/REQUEST-920.conf"
echo 'SecAction "id:900000,setvar:tx.paranoia=2"' >"$r/crs/setup.conf"
echo 'SecRule ARGS "old b" "id:930100"' >"$r/crs/rules/REQUEST-930.conf.disabled"
rm "$r/crs/rules/REQUEST-930.conf"
echo 'SecRule ARGS "old c" "id:1000100"' >"$TMP/old-wp.conf"
rm "$r/wp/rules/WP.conf" && ln -s "$TMP/old-wp.conf" "$r/wp/rules/WP.conf"
echo 'SecRule ARGS "old a" "id:920100"' >"$r/crs/rules/php-errors.data"
out=$(bash "$d/scripts/seed-modsecurity.sh" "$d" "$TMP/shipped")

check "a file an earlier release shipped is brought up to the shipped version" 'SecRule ARGS "a" "id:920100"' "$(cat "$r/crs/rules/REQUEST-920.conf")"
check "and that is said" "ModSecurity ruleset files on this host brought up to the shipped version (each was a copy an earlier release shipped):
  updated:     $r/crs/rules/REQUEST-920.conf" "$(sed -n '1,2p' <<<"$out")"
check "a file the operator edited keeps the edit" 'SecAction "id:900000,setvar:tx.paranoia=2"' "$(cat "$r/crs/setup.conf")"
check "a switched-off rule stays off and as it is, even an earlier release's copy" 'no|SecRule ARGS "old b" "id:930100"' \
    "$(has "$r/crs/rules/REQUEST-930.conf")|$(cat "$r/crs/rules/REQUEST-930.conf.disabled")"
check "a link is not written through" 'SecRule ARGS "old c" "id:1000100"|yes' "$(cat "$TMP/old-wp.conf")|$([ -L "$r/wp/rules/WP.conf" ] && echo yes)"
check "a version of another file does not count" 'SecRule ARGS "old a" "id:920100"' "$(cat "$r/crs/rules/php-errors.data")"
check "everything kept is still listed, with no note" "ModSecurity ruleset files on this host that differ from the shipped ones (left as they are):
  changed:     $r/crs/rules/REQUEST-930.conf.disabled
  changed:     $r/crs/rules/php-errors.data
  changed:     $r/crs/setup.conf
  changed:     $r/wp/rules/WP.conf" "$(sed -n '3,$p' <<<"$out")"
out=$(bash "$d/scripts/seed-modsecurity.sh" "$d" "$TMP/shipped")
check "a second run updates nothing" "0" "$(grep -c 'updated:' <<<"$out")"
out=$(bash "$d/scripts/seed-modsecurity.sh" "$d" "$TMP/missing")
check "a list that is not there replaces nothing and says why" "1" "$(grep -c 'none was replaced' <<<"$out")"

# installer.sh lists the versions from the release clone's history, before .git goes.
eval "$(sed -n '/^list_shipped_modsecurity_rules() {/,/^}/p' "$SCRIPT_DIR/installer.sh")"
echo_warning() { echo "warn:$1"; }
REPO="$TMP/repo"
g() { git -C "$REPO" -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false "$@" >/dev/null 2>&1; }
git init -q -b main "$REPO"
git -C "$REPO" config uploadpack.allowFilter true
shipped_tree "$REPO"
echo 'SecRule ARGS "old a" "id:920100"' >"$REPO/templates/config/modsecurity/rulesets/crs/rules/REQUEST-920.conf"
echo 'not a ruleset' >"$REPO/templates/config/modsecurity/notes.txt"
g add -A && g commit -m 'release 1'
OLD=$(git -C "$REPO" rev-parse HEAD:templates/config/modsecurity/rulesets/crs/rules/REQUEST-920.conf)
echo 'SecRule ARGS "a" "id:920100"' >"$REPO/templates/config/modsecurity/rulesets/crs/rules/REQUEST-920.conf"
g commit -am 'release 2'
NEW=$(git -C "$REPO" rev-parse HEAD:templates/config/modsecurity/rulesets/crs/rules/REQUEST-920.conf)
git clone -q --filter=blob:none --no-checkout "file://$REPO" "$TMP/blobless" 2>/dev/null
git -C "$TMP/blobless" remote set-url origin "$TMP/gone"
out=$(list_shipped_modsecurity_rules "$TMP/blobless" "$TMP/list")
check "a blobless clone lists every version of a ruleset file" "$(printf '%s\n' "$NEW crs/rules/REQUEST-920.conf" "$OLD crs/rules/REQUEST-920.conf" | LC_ALL=C sort)" \
    "$(grep ' crs/rules/REQUEST-920.conf$' "$TMP/list")"
check "every shipped ruleset file once at least, nothing outside the rulesets" "5|0" \
    "$(cut -d' ' -f2 "$TMP/list" | sort -u | wc -l | tr -d ' ')|$(grep -c 'notes.txt\|main.conf' "$TMP/list")"
check "and says nothing" "" "$out"
mkdir -p "$REPO/config/modsecurity/rulesets/crs/rules"
echo 'SecRule ARGS "old a" "id:920100"' >"$REPO/config/modsecurity/rulesets/crs/rules/REQUEST-920.conf"
out=$(bash "$REPO/scripts/seed-modsecurity.sh" "$REPO" "$TMP/list")
check "so a host still on release 1's rule gets release 2's" 'SecRule ARGS "a" "id:920100"' \
    "$(cat "$REPO/config/modsecurity/rulesets/crs/rules/REQUEST-920.conf")"
git clone -q --depth 1 "file://$REPO" "$TMP/shallow" 2>/dev/null
out=$(list_shipped_modsecurity_rules "$TMP/shallow" "$TMP/list")
check "a shallow clone is reported and lists nothing" "warn:Could not read the engine's history; ModSecurity rule files an earlier release shipped are not brought up to date|no" \
    "$out|$(has "$TMP/list")"

check "the installer seeds through the same script, with the versions it listed" "1|1" \
    "$(grep -c 'scripts/seed-modsecurity.sh /opt/panelalpha/shared-hosting "$INSTALL_DIR/modsecurity-shipped"$' "$SCRIPT_DIR/installer.sh")|$(grep -c '^    list_shipped_modsecurity_rules "$INSTALL_DIR/src" "$INSTALL_DIR/modsecurity-shipped"$' "$SCRIPT_DIR/installer.sh")"
check "while the clone still has its history" "list_shipped_modsecurity_rules|rm" \
    "$(sed -n '/^download_engine_from_repository() {/,/^}/p' "$SCRIPT_DIR/installer.sh" | grep -oE '^    (list_shipped_modsecurity_rules|rm -rf "\$INSTALL_DIR/src/\.git")' | cut -d' ' -f5 | tr '\n' '|' | sed 's/|$//')"
check "no seeding path copies the rulesets by itself" "" "$(grep -l 'templates/config/modsecurity/\.' "$SCRIPT_DIR/installer.sh" "$SCRIPT_DIR/bootstrap-from-source.sh")"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
