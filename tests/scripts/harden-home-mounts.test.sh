#!/bin/bash
#
# harden-home-mounts.sh against stubbed findmnt/mount/systemctl, without root or a real mount.
#
#   bash tests/scripts/harden-home-mounts.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
SCRIPT="$SCRIPT_DIR/harden-home-mounts.sh"
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
has() { grep -qF -- "$2" "$1" && echo yes || echo no; }
called() { grep -qxF -- "$2" "$1" && echo yes || echo no; }

BIN="$TMP/bin"
mkdir -p "$BIN"
cat >"$BIN/findmnt" <<'EOF'
#!/bin/bash
echo "findmnt $*" >>"$STUB_DIR/calls"
column="$3"
[ "$2" = "-r" ] && column="$4"
case "$column" in
TARGET) cat "$STUB_DIR/target" ;;
OPTIONS) cat "$STUB_DIR/options" ;;
FSROOT) cat "$STUB_DIR/fsroot" ;;
esac
EOF
cat >"$BIN/mount" <<'EOF'
#!/bin/bash
echo "mount $*" >>"$STUB_DIR/calls"
if [ "$1" = "--bind" ]; then
    [ -z "${BIND_FAILS:-}" ] || exit 1
    echo "$3" >"$STUB_DIR/target"
    echo "$3" >"$STUB_DIR/fsroot"
    exit 0
fi
[ -z "${REMOUNT_FAILS:-}" ] || exit 1
case "$2" in *nosuid,nodev*) echo "rw,nosuid,nodev,relatime" >"$STUB_DIR/options" ;; esac
EOF
cat >"$BIN/umount" <<'EOF'
#!/bin/bash
echo "umount $*" >>"$STUB_DIR/calls"
cp "$STUB_DIR/target.before" "$STUB_DIR/target"
echo / >"$STUB_DIR/fsroot"
EOF
cat >"$BIN/systemctl" <<'EOF'
#!/bin/bash
echo "systemctl $*" >>"$STUB_DIR/calls"
case "$1" in
enable) [ -z "${ENABLE_FAILS:-}" ] ;;
restart)
    unit="$PANELALPHA_SYSTEMD_DIR/$2"
    # shellcheck disable=SC2046
    env $(sed -n 's/^Environment=//p' "$unit") $(sed -n 's/^ExecStart=//p' "$unit") >>"$STUB_DIR/unit-out" 2>&1
    ;;
esac
EOF
cat >"$BIN/systemd-detect-virt" <<'EOF'
#!/bin/bash
[ -n "${IN_CONTAINER:-}" ]
EOF
chmod +x "$BIN"/*

# The same tools without systemctl, for a host that has none.
NOSYSTEMD="$TMP/nosystemd"
mkdir -p "$NOSYSTEMD"
for tool in findmnt mount umount systemd-detect-virt; do ln -s "$BIN/$tool" "$NOSYSTEMD/$tool"; done
for tool in bash env sed cat tr grep dirname basename cp; do ln -s "$(command -v "$tool")" "$NOSYSTEMD/$tool"; done

# fresh <name> [VAR=value ...]: a new state, /home on / unless the variables say otherwise.
fresh() {
    local name="$1"
    shift
    local dir="$TMP/$name"
    mkdir -p "$dir/systemd"
    : >"$dir/calls"
    local target=/ fsroot=/ options=rw,relatime
    for pair in "$@"; do
        case "$pair" in
        FM_TARGET=*) target="${pair#*=}" ;;
        FM_FSROOT=*) fsroot="${pair#*=}" ;;
        FM_OPTIONS=*) options="${pair#*=}" ;;
        esac
    done
    echo "$target" >"$dir/target"
    echo "$target" >"$dir/target.before"
    echo "$fsroot" >"$dir/fsroot"
    echo "$options" >"$dir/options"
}
# again <name> [args] [-- VAR=value ...]: the script on the state <name> holds.
again() {
    local name="$1"
    shift
    local args=() vars=()
    while [ $# -gt 0 ] && [ "$1" != "--" ]; do args+=("$1"); shift; done
    [ "${1:-}" = "--" ] && shift
    vars=("$@")
    local dir="$TMP/$name"
    env PATH="${STUB_PATH:-$BIN:$PATH}" STUB_DIR="$dir" PANELALPHA_SYSTEMD_DIR="$dir/systemd" \
        PANELALPHA_HOME_ALLOW_CONTAINER=1 "${vars[@]}" bash "$SCRIPT" "${args[@]}" >"$dir/out" 2>&1
    echo $? >"$dir/rc"
}
run() {
    local name="$1"
    shift
    local args=() vars=()
    while [ $# -gt 0 ] && [ "$1" != "--" ]; do args+=("$1"); shift; done
    [ "${1:-}" = "--" ] && shift
    vars=("$@")
    fresh "$name" "${vars[@]}"
    again "$name" "${args[@]}" -- "${vars[@]}"
}
rc() { cat "$TMP/$1/rc"; }
calls() { echo "$TMP/$1/calls"; }
unit_file() { echo "$TMP/$1/systemd/panelalpha-home-mounts.service"; }
options() { cat "$TMP/$1/options"; }

echo "=== install on a host with /home under /: unit installed, started, home bound ==="
run fresh
check "exits 0" 0 "$(rc fresh)"
check "unit written" yes "$([ -f "$(unit_file fresh)" ] && echo yes || echo no)"
check "unit runs before Docker" yes "$(has "$(unit_file fresh)" 'Before=docker.service')"
check "Docker pulls the unit in" yes "$(has "$(unit_file fresh)" 'WantedBy=docker.service multi-user.target')"
check "unit waits for the homes' filesystem" yes "$(has "$(unit_file fresh)" 'RequiresMountsFor=/home')"
check "unit is a oneshot that stays active" yes "$(has "$(unit_file fresh)" 'RemainAfterExit=yes')"
check "unit runs this script with --boot" yes "$(has "$(unit_file fresh)" "ExecStart=/bin/bash $SCRIPT --boot")"
check "daemon-reload after writing it" yes "$(called "$(calls fresh)" 'systemctl daemon-reload')"
check "unit enabled" yes "$(called "$(calls fresh)" 'systemctl enable panelalpha-home-mounts.service')"
check "unit started" yes "$(called "$(calls fresh)" 'systemctl restart panelalpha-home-mounts.service')"
check "the unit bound /home onto itself" yes "$(called "$(calls fresh)" 'mount --bind /home /home')"
check "the unit added the flags" yes "$(called "$(calls fresh)" 'mount -o remount,bind,nosuid,nodev /home')"
check "/home ends up nosuid,nodev" rw,nosuid,nodev,relatime "$(options fresh)"
check "says the unit keeps it" yes "$(has "$TMP/fresh/out" 'keeps it so at every boot')"

echo "=== install again (an update): same unit, nothing remounted ==="
cp "$(unit_file fresh)" "$TMP/fresh/unit.before"
: >"$(calls fresh)"
again fresh
check "exits 0" 0 "$(rc fresh)"
check "unit unchanged" yes "$(cmp -s "$(unit_file fresh)" "$TMP/fresh/unit.before" && echo yes || echo no)"
check "no daemon-reload for an unchanged unit" no "$(has "$(calls fresh)" 'daemon-reload')"
check "no mount call" no "$(has "$(calls fresh)" 'mount ')"

echo "=== --boot when /home is its own filesystem: remounted, never bound ==="
run own --boot -- FM_TARGET=/home
check "exits 0" 0 "$(rc own)"
check "remount with the flags" yes "$(called "$(calls own)" 'mount -o remount,nosuid,nodev /home')"
check "no bind" no "$(has "$(calls own)" 'mount --bind')"
check "no unit written by --boot" no "$([ -f "$(unit_file own)" ] && echo yes || echo no)"

echo "=== --boot when the self-bind lost its flags: remounted as a bind ==="
run lost --boot -- FM_TARGET=/home FM_FSROOT=/home
check "exits 0" 0 "$(rc lost)"
check "remount keeps it a bind" yes "$(called "$(calls lost)" 'mount -o remount,bind,nosuid,nodev /home')"
check "no second bind on top" no "$(has "$(calls lost)" 'mount --bind')"

echo "=== --boot when already hardened: nothing touched ==="
run done2 --boot -- FM_TARGET=/home FM_FSROOT=/home FM_OPTIONS=rw,nosuid,nodev
check "exits 0" 0 "$(rc done2)"
check "no mount call" no "$(has "$(calls done2)" 'mount ')"

echo "=== --boot whose remount fails: the fresh bind is taken down, the unit fails ==="
run badremount --boot -- REMOUNT_FAILS=1
check "exits non-zero, so systemctl shows the unit failed" 1 "$(rc badremount)"
check "the bind it made is unmounted" yes "$(called "$(calls badremount)" 'umount /home')"
check "/home is back to a plain directory on /" / "$(cat "$TMP/badremount/target")"
check "says it was not applied" yes "$(has "$TMP/badremount/out" 'NOT mounted nosuid,nodev')"

echo "=== install whose unit cannot mount: warned, install not aborted ==="
run installfail -- REMOUNT_FAILS=1
check "exits 0" 0 "$(rc installfail)"
check "points at the unit's status" yes "$(has "$TMP/installfail/out" 'systemctl status panelalpha-home-mounts.service')"

echo "=== unit cannot be enabled: hardened now, warned about the reboot ==="
run noenable -- ENABLE_FAILS=1
check "exits 0" 0 "$(rc noenable)"
check "warns it lasts until the next reboot" yes "$(has "$TMP/noenable/out" 'until the next reboot only')"
check "still hardened now" rw,nosuid,nodev,relatime "$(options noenable)"

echo "=== no systemctl: hardened now, warned about the reboot ==="
fresh nosystemd
STUB_PATH="$NOSYSTEMD" again nosystemd
check "exits 0" 0 "$(rc nosystemd)"
check "warns it lasts until the next reboot" yes "$(has "$TMP/nosystemd/out" 'no systemctl')"
check "still hardened now" rw,nosuid,nodev,relatime "$(options nosystemd)"

echo "=== opt-out ==="
run optout -- PANELALPHA_HOME_HARDENING=0
check "exits 0" 0 "$(rc optout)"
check "nothing ran" 0 "$(wc -l <"$(calls optout)")"
check "no unit" no "$([ -f "$(unit_file optout)" ] && echo yes || echo no)"

echo "=== inside a container ==="
run container -- PANELALPHA_HOME_ALLOW_CONTAINER=0 IN_CONTAINER=1
check "exits 0" 0 "$(rc container)"
check "says why" yes "$(has "$TMP/container/out" 'inside a container')"
check "no unit" no "$([ -f "$(unit_file container)" ] && echo yes || echo no)"

echo "=== home path missing ==="
run nohome -- PANELALPHA_HOME_PATH=/does/not/exist
check "exits 0" 0 "$(rc nohome)"
check "says why" yes "$(has "$TMP/nohome/out" 'does not exist')"

echo "=== /etc/fstab is never written ==="
check "no fstab path or redirect in the script" 0 "$(grep -cE 'FSTAB|>[^&]*fstab' "$SCRIPT")"

echo "=== the installers call it, survive its failure, and the uninstaller removes the unit ==="
for f in installer.sh bootstrap-from-source.sh; do
    line=$(grep -F 'harden-home-mounts.sh' "$SCRIPT_DIR/$f" | grep -v '^[[:space:]]*#' | head -1)
    check "$f calls harden-home-mounts.sh" yes "$([ -n "$line" ] && echo yes || echo no)"
    check "$f tolerates its failure" yes "$(printf '%s' "$line" | grep -qE '\|\|[[:space:]]*(echo_warning|warn)|\|\|[[:space:]]*$' && echo yes || echo no)"
done
order=$(grep -nE '^[[:space:]]+configure_(quota|home_mounts)$' "$SCRIPT_DIR/installer.sh" | cut -d: -f2 | tr -d ' ' | tr '\n' ' ')
check "installer.sh runs it right after quota" "configure_quota configure_home_mounts " "$order"
check "uninstall.sh deletes the unit" yes "$(grep -E '^[[:space:]]+rm -f ' "$SCRIPT_DIR/uninstall.sh" | grep -qE '(\$\{?SYSTEMD_UNIT_DIR\}?|/etc/systemd/system)/panelalpha-home-mounts\.service' && echo yes || echo no)"
check "uninstall.sh runs that step" yes "$(grep -qx 'remove_boot_units' "$SCRIPT_DIR/uninstall.sh" && echo yes || echo no)"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
