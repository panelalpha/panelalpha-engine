#!/bin/bash
#
# Which host AppArmor profiles configure-apparmor.sh disables, checked without
# root against a fixture profile directory and a stub apparmor_parser.
#
#   bash scripts/configure-apparmor.test.sh

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

D="$TMP/apparmor.d"
mkdir -p "$D/abstractions" "$D/local" "$TMP/bin"
cat >"$D/usr.sbin.rsyslogd" <<'EOF'
# rsyslogd, as Ubuntu 24.04 ships it
abi <abi/4.0>,
include <tunables/global>
profile rsyslogd /usr/sbin/rsyslogd {
  /etc/rsyslog.conf r,
}
EOF
cat >"$D/transmission" <<'EOF'
profile transmission-daemon /usr/bin/transmission-daemon flags=(complain) {
  /etc/transmission-daemon/** r,
}
EOF
cat >"$D/usr.bin.man" <<'EOF'
/usr/bin/man flags=(attach_disconnected) {
  /usr/share/man/** r,
}
EOF
cat >"$D/mosquitto" <<'EOF'
profile mosquitto @{exec_path} {
}
EOF
cat >"$D/chrome" <<'EOF'
profile chrome /opt/google/chrome/chrome flags=(unconfined) {
  userns,
}
EOF
cat >"$D/fusermount3" <<'EOF'
profile fusermount3 /usr/bin/fusermount3 {
}
EOF
cat >"$D/usr.lib.snapd.snap-confine.real" <<'EOF'
/usr/lib/snapd/snap-confine (attach_disconnected) {
}
EOF
cat >"$D/unprivileged_userns" <<'EOF'
profile unprivileged_userns {
  audit deny capability,
}
EOF
cat >"$D/no-attachment" <<'EOF'
profile helper flags=(complain) {
}
EOF
echo '/usr/sbin/rsyslogd r,' >"$D/abstractions/base"

# Records what would be unloaded.
cat >"$TMP/bin/apparmor_parser" <<EOF
#!/bin/sh
echo "\$*" >>"$TMP/parser.log"
EOF
chmod +x "$TMP/bin/apparmor_parser"

run() {
    PATH="$TMP/bin:$PATH" PANELALPHA_APPARMOR_DIR="$D" bash "$SCRIPT_DIR/configure-apparmor.sh" "$@" >"$TMP/out" 2>&1
}
disabled() {
    ls "$D/disable" 2>/dev/null | sort | tr '\n' ' '
}

echo "=== a dry run lists and changes nothing ==="
PANELALPHA_APPARMOR_NO_APPLY=1 run
check "exits 0" "0" "$?"
check "lists the confining path profiles" \
    "mosquitto transmission usr.bin.man usr.sbin.rsyslogd " \
    "$(sed -n 's/^Would disable AppArmor profile \([^ ]*\) .*/\1/p' "$TMP/out" | sort | tr '\n' ' ')"
check "names the path" "yes" "$(grep -q 'usr.sbin.rsyslogd (attaches to /usr/sbin/rsyslogd)' "$TMP/out" && echo yes || echo no)"
check "nothing disabled" "" "$(disabled)"
check "parser not called" "no" "$([[ -f "$TMP/parser.log" ]] && echo yes || echo no)"

echo "=== it disables them ==="
run
check "exits 0" "0" "$?"
check "disable links" "mosquitto transmission usr.bin.man usr.sbin.rsyslogd " "$(disabled)"
check "link points at the profile" "$D/usr.sbin.rsyslogd" "$(readlink "$D/disable/usr.sbin.rsyslogd")"
check "unloaded" "-R $D/usr.sbin.rsyslogd" "$(grep rsyslogd "$TMP/parser.log")"
check "fusermount3 kept" "no" "$([[ -e "$D/disable/fusermount3" ]] && echo yes || echo no)"
check "snap-confine kept" "no" "$([[ -e "$D/disable/usr.lib.snapd.snap-confine.real" ]] && echo yes || echo no)"
check "unconfined chrome kept" "no" "$([[ -e "$D/disable/chrome" ]] && echo yes || echo no)"
check "profile without attachment kept" "no" "$([[ -e "$D/disable/no-attachment" ]] && echo yes || echo no)"
check "abstractions untouched" "no" "$([[ -e "$D/disable/base" ]] && echo yes || echo no)"

echo "=== a re-run changes nothing ==="
: >"$TMP/parser.log"
run
check "exits 0" "0" "$?"
check "same links" "mosquitto transmission usr.bin.man usr.sbin.rsyslogd " "$(disabled)"
check "nothing unloaded again" "" "$(cat "$TMP/parser.log")"
check "says so" "yes" "$(grep -q 'No path-attached AppArmor profile left to disable' "$TMP/out" && echo yes || echo no)"

echo "=== PANELALPHA_APPARMOR=0 skips it ==="
rm -rf "$D/disable"
PANELALPHA_APPARMOR=0 run
check "exits 0" "0" "$?"
check "nothing disabled" "" "$(disabled)"

echo "=== the installers call it ==="
for f in installer.sh bootstrap-from-source.sh; do
    line=$(grep -F 'configure-apparmor.sh' "$SCRIPT_DIR/$f" | grep -v '^[[:space:]]*#' | head -1)
    check "$f calls configure-apparmor.sh" yes "$([ -n "$line" ] && echo yes || echo no)"
done

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
