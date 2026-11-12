#!/bin/bash
#
# configure-quota.sh against stubbed findmnt/quotaon/mount/systemctl, without root or a real filesystem.
#
#   bash tests/scripts/configure-quota.test.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../../scripts" && pwd)"
SCRIPT="$SCRIPT_DIR/configure-quota.sh"
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

# Stubs log each call to $STUB_DIR/calls; quota state and mount options live beside it.
BIN="$TMP/bin"
mkdir -p "$BIN"
cat >"$BIN/findmnt" <<'EOF'
#!/bin/bash
echo "findmnt $*" >>"$STUB_DIR/calls"
raw=0
[[ " $* " == *" -r "* ]] && raw=1
if [ "$raw" = 1 ] && [ "$4" = "TARGET,FSROOT,MAJ:MIN" ]; then
    printf '%b' "${FM_MOUNTS:-}"
    exit 0
fi
path="${*: -1}"
column="$3"
[ "$raw" = 1 ] && column="$4"
case "$column" in
TARGET) if [ -n "${FM_BIND:-}" ] && [ "$path" = / ]; then echo /; else echo "$FM_TARGET"; fi ;;
SOURCE) echo "/dev/sda1" ;;
FSTYPE) echo "$FM_FSTYPE" ;;
OPTIONS) cat "$STUB_DIR/options" ;;
FSROOT) if [ -n "${FM_BIND:-}" ] && [ "$path" != / ]; then echo "$FM_BIND"; else echo /; fi ;;
MAJ:MIN) if [ "$raw" = 1 ]; then echo 8:1; else echo "  8:1  "; fi ;;
esac
EOF
cat >"$BIN/quotaon" <<'EOF'
#!/bin/bash
echo "quotaon $*" >>"$STUB_DIR/calls"
target="${*: -1}"
if [ "$1" = "-p" ]; then
    # Like the real one: exit status is non-zero when quota IS on.
    if [ "$(cat "$STUB_DIR/state" 2>/dev/null)" = on ]; then
        echo "user quota on $target (/dev/sda1) is on"; exit 1
    fi
    echo "user quota on $target (/dev/sda1) is off"; exit 0
fi
[ -n "${QUOTAON_FAILS:-}" ] && { echo "quotaon: cannot" >&2; exit 1; }
echo on >"$STUB_DIR/state"
EOF
cat >"$BIN/mount" <<'EOF'
#!/bin/bash
echo "mount $*" >>"$STUB_DIR/calls"
[ -z "${MOUNT_FAILS:-}" ] || exit 1
case "$2" in remount,*) echo "rw,relatime,${2#remount,}" >"$STUB_DIR/options" ;; esac
EOF
cat >"$BIN/dumpe2fs" <<'EOF'
#!/bin/bash
echo "dumpe2fs $*" >>"$STUB_DIR/calls"
echo "Filesystem features:      has_journal ext_attr${QUOTA_FEATURE:+ quota} extent"
EOF
cat >"$BIN/modprobe" <<'EOF'
#!/bin/bash
echo "modprobe $*" >>"$STUB_DIR/calls"
[ -z "${NO_MODULE:-}" ]
EOF
cat >"$BIN/systemctl" <<'EOF'
#!/bin/bash
echo "systemctl $*" >>"$STUB_DIR/calls"
case "$1" in
enable) [ -z "${ENABLE_FAILS:-}" ] ;;
start | restart)
    unit="$PANELALPHA_SYSTEMD_DIR/$2"
    # shellcheck disable=SC2046
    env $(sed -n 's/^Environment=//p' "$unit") $(sed -n 's/^ExecStart=//p' "$unit") >>"$STUB_DIR/unit-out" 2>&1
    ;;
esac
EOF
for cmd in quotacheck setquota; do
    printf '#!/bin/bash\necho "%s $*" >>"$STUB_DIR/calls"\n' "$cmd" >"$BIN/$cmd"
done
cat >"$BIN/systemd-detect-virt" <<'EOF'
#!/bin/bash
[ -n "${IN_CONTAINER:-}" ]
EOF
chmod +x "$BIN"/*

# The same tools without systemctl, for a host that has none.
NOSYSTEMD="$TMP/nosystemd"
mkdir -p "$NOSYSTEMD"
for tool in findmnt quotaon mount dumpe2fs modprobe quotacheck setquota systemd-detect-virt; do
    ln -s "$BIN/$tool" "$NOSYSTEMD/$tool"
done
for tool in bash env sed cat tr grep awk dirname basename uname; do ln -s "$(command -v "$tool")" "$NOSYSTEMD/$tool"; done

FSTAB_ORIG="$TMP/fstab.orig"
cat >"$FSTAB_ORIG" <<'EOF'
# /etc/fstab: static file system information.
/dev/disk/by-uuid/9e4ec673-4345-4852-8a69-0f7f3df29989	/	ext4	defaults	0 1
/dev/disk/by-uuid/A948-365C	/boot/efi	vfat	defaults	0 1
/dev/sdb1	/home	ext4	defaults,noatime	0 2
EOF

# Each case gets its own stand-in filesystem root.
#   prepare <name> [VAR=value ...]   a new state: quota off, mount options rw,relatime
#   go <name> [args] [-- VAR=value ...]   the script on that state
prepare() {
    local name="$1"
    shift
    local dir="$TMP/$name" options=rw,relatime
    mkdir -p "$dir/fs" "$dir/systemd"
    cp "$FSTAB_ORIG" "$dir/fstab"
    : >"$dir/calls"
    for pair in "$@"; do
        case "$pair" in FM_OPTIONS=*) options="${pair#*=}" ;; esac
    done
    echo "$options" >"$dir/options"
}
go() {
    local name="$1"
    shift
    local args=() vars=()
    while [ $# -gt 0 ] && [ "$1" != "--" ]; do args+=("$1"); shift; done
    [ "${1:-}" = "--" ] && shift
    vars=("$@")
    local dir="$TMP/$name"
    env PATH="${STUB_PATH:-$BIN:$PATH}" STUB_DIR="$dir" PANELALPHA_SYSTEMD_DIR="$dir/systemd" \
        PANELALPHA_QUOTA_ALLOW_CONTAINER=1 FM_TARGET="$dir/fs" FM_FSTYPE=ext4 "${vars[@]}" \
        bash "$SCRIPT" "${args[@]}" >"$dir/out" 2>&1
    echo $? >"$dir/rc"
}
run() {
    local name="$1"
    shift
    local args=() vars=()
    while [ $# -gt 0 ] && [ "$1" != "--" ]; do args+=("$1"); shift; done
    [ "${1:-}" = "--" ] && shift
    vars=("$@")
    prepare "$name" "${vars[@]}"
    go "$name" "${args[@]}" -- "${vars[@]}"
}
rc() { cat "$TMP/$1/rc"; }
calls() { echo "$TMP/$1/calls"; }
fs() { echo "$TMP/$1/fs"; }
unit_file() { echo "$TMP/$1/systemd/panelalpha-quota.service"; }
has_unit() { [ -f "$(unit_file "$1")" ] && echo yes || echo no; }
fstab_kept() { cmp -s "$FSTAB_ORIG" "$TMP/$1/fstab" && echo yes || echo no; }
quota_state() { cat "$TMP/$1/state" 2>/dev/null || echo off; }

echo "=== ext4, quota off: turned on now, the unit keeps it on, fstab untouched ==="
run fresh
check "exits 0" 0 "$(rc fresh)"
check "live remount with journaled quota" yes "$(called "$(calls fresh)" "mount -o remount,usrjquota=aquota.user,jqfmt=vfsv1 $(fs fresh)")"
check "first quotacheck creates the file" yes "$(called "$(calls fresh)" "quotacheck -cumF vfsv1 $(fs fresh)")"
check "quotaon" yes "$(called "$(calls fresh)" "quotaon -u $(fs fresh)")"
check "quota is on" on "$(quota_state fresh)"
check "says it is on" yes "$(has "$TMP/fresh/out" "user quota turned on for $(fs fresh)")"
check "points at stored limits" yes "$(has "$TMP/fresh/out" 'project:quota:rebuild --all')"
check "fstab byte-identical" yes "$(fstab_kept fresh)"
check "unit written" yes "$(has_unit fresh)"
check "unit runs after the system's own quota units" yes "$(has "$(unit_file fresh)" 'After=local-fs.target systemd-quotacheck.service quotaon.service')"
check "unit runs before Docker" yes "$(has "$(unit_file fresh)" 'Before=docker.service')"
check "Docker pulls the unit in" yes "$(has "$(unit_file fresh)" 'WantedBy=docker.service multi-user.target')"
check "unit waits for the homes' filesystem" yes "$(has "$(unit_file fresh)" 'RequiresMountsFor=/home')"
check "unit runs this script with --boot" yes "$(has "$(unit_file fresh)" "ExecStart=/bin/bash $SCRIPT --boot")"
check "daemon-reload, enable, start" yes "$(called "$(calls fresh)" 'systemctl daemon-reload')$(called "$(calls fresh)" 'systemctl enable panelalpha-quota.service' | sed 's/yes//')$(called "$(calls fresh)" 'systemctl start panelalpha-quota.service' | sed 's/yes//')"
check "says the unit keeps it" yes "$(has "$TMP/fresh/out" 'turns it on at every boot')"

echo "=== an update on that host: already on, nothing counted or remounted again ==="
cp "$(unit_file fresh)" "$TMP/fresh/unit.before"
: >"$(calls fresh)"
go fresh
check "exits 0" 0 "$(rc fresh)"
check "reports already on" yes "$(has "$TMP/fresh/out" 'already on')"
check "no remount" no "$(has "$(calls fresh)" 'mount ')"
check "no quotacheck on a live quota" no "$(has "$(calls fresh)" 'quotacheck')"
check "unit unchanged, no daemon-reload" "yes no" "$(cmp -s "$(unit_file fresh)" "$TMP/fresh/unit.before" && echo yes || echo no) $(has "$(calls fresh)" 'daemon-reload')"

echo "=== --boot after a reboot: options and quota gone, limits kept ==="
prepare reboot
touch "$(fs reboot)/aquota.user"
go reboot --boot
check "exits 0" 0 "$(rc reboot)"
check "remounted with journaled quota" yes "$(called "$(calls reboot)" "mount -o remount,usrjquota=aquota.user,jqfmt=vfsv1 $(fs reboot)")"
check "quotacheck reads the existing limits (no -c)" yes "$(called "$(calls reboot)" "quotacheck -umF vfsv1 $(fs reboot)")"
check "never a quotacheck that starts from nothing" no "$(has "$(calls reboot)" 'quotacheck -c')"
check "quota is on" on "$(quota_state reboot)"
check "--boot writes no unit" no "$(has_unit reboot)"

echo "=== --boot on a host whose fstab quota option systemd already used ==="
prepare oldfstab FM_OPTIONS=rw,relatime,usrjquota=aquota.user,jqfmt=vfsv1
echo on >"$TMP/oldfstab/state"
go oldfstab --boot
check "exits 0" 0 "$(rc oldfstab)"
check "no second quotacheck" no "$(has "$(calls oldfstab)" 'quotacheck')"
check "no remount" no "$(has "$(calls oldfstab)" 'mount ')"

echo "=== --boot where the fstab option is there but quota is off: on, limits kept ==="
prepare halfway FM_OPTIONS=rw,relatime,usrjquota=aquota.user,jqfmt=vfsv1
touch "$(fs halfway)/aquota.user"
go halfway --boot -- FM_OPTIONS=rw,relatime,usrjquota=aquota.user,jqfmt=vfsv1
check "exits 0" 0 "$(rc halfway)"
check "no remount needed" no "$(has "$(calls halfway)" 'mount -o remount')"
check "quotacheck keeps the limits" yes "$(called "$(calls halfway)" "quotacheck -umF vfsv1 $(fs halfway)")"
check "quota is on" on "$(quota_state halfway)"

echo "=== --boot whose quotaon fails: the unit fails, visibly ==="
run bootfail --boot -- QUOTAON_FAILS=1
check "exits non-zero" 1 "$(rc bootfail)"
check "warns limits are not enforced" yes "$(has "$TMP/bootfail/out" 'will NOT be enforced')"

echo "=== an fstab quota option an older engine added is left as it is ==="
prepare keepline FM_OPTIONS=rw,relatime,usrjquota=aquota.user,jqfmt=vfsv1
sed -i 's#\t/\text4\tdefaults#\t/\text4\tdefaults,usrjquota=aquota.user,jqfmt=vfsv1#' "$TMP/keepline/fstab"
cp "$TMP/keepline/fstab" "$TMP/keepline/fstab.before"
echo on >"$TMP/keepline/state"
go keepline -- FM_OPTIONS=rw,relatime,usrjquota=aquota.user,jqfmt=vfsv1
check "exits 0" 0 "$(rc keepline)"
check "fstab line kept byte for byte" yes "$(cmp -s "$TMP/keepline/fstab" "$TMP/keepline/fstab.before" && echo yes || echo no)"
check "unit installed anyway" yes "$(has_unit keepline)"

echo "=== /home is the nosuid,nodev self-bind: quota goes to / ==="
run bound -- FM_TARGET=/home FM_BIND=/home "FM_MOUNTS=/ / 8:1\n/home /home 8:1\n"
check "exits 0" 0 "$(rc bound)"
check "remounts /, not the bind" yes "$(called "$(calls bound)" 'mount -o remount,usrjquota=aquota.user,jqfmt=vfsv1 /')"
check "never remounts the bind" no "$(called "$(calls bound)" 'mount -o remount,usrjquota=aquota.user,jqfmt=vfsv1 /home')"
check "quotaon on /" yes "$(called "$(calls bound)" 'quotaon -u /')"
check "says it is on for /" yes "$(has "$TMP/bound/out" 'user quota turned on for / ')"
check "fstab byte-identical" yes "$(fstab_kept bound)"

echo "=== ext4 quota feature: only switched on ==="
run feature -- QUOTA_FEATURE=1
check "exits 0" 0 "$(rc feature)"
check "quotaon" yes "$(called "$(calls feature)" "quotaon -u $(fs feature)")"
check "no remount" no "$(has "$(calls feature)" 'mount ')"
check "no quotacheck" no "$(has "$(calls feature)" 'quotacheck')"
check "unit installed" yes "$(has_unit feature)"

echo "=== opt-out ==="
run optout -- PANELALPHA_QUOTA=0
check "exits 0" 0 "$(rc optout)"
check "nothing ran" "0" "$(wc -l <"$(calls optout)")"
check "no unit" no "$(has_unit optout)"

echo "=== inside a container ==="
run container -- PANELALPHA_QUOTA_ALLOW_CONTAINER=0 IN_CONTAINER=1
check "exits 0" 0 "$(rc container)"
check "says why" yes "$(has "$TMP/container/out" 'inside a container')"
check "no unit" no "$(has_unit container)"

echo "=== XFS: instructions, no change ==="
run xfsroot -- FM_TARGET=/ FM_FSTYPE=xfs
check "exits 0" 0 "$(rc xfsroot)"
check "names rootflags for a root XFS" yes "$(has "$TMP/xfsroot/out" 'rootflags=uquota')"
run xfs -- FM_FSTYPE=xfs
check "says where uquota goes for another XFS" yes "$(has "$TMP/xfs/out" "Add 'uquota' to its options in /etc/fstab")"
check "no remount" no "$(has "$(calls xfs)" 'mount ')"
check "no unit" no "$(has_unit xfs)"

echo "=== btrfs / overlay: skipped ==="
run btrfs -- FM_FSTYPE=btrfs
check "exits 0" 0 "$(rc btrfs)"
check "warns limits are not enforced" yes "$(has "$TMP/btrfs/out" 'will NOT be enforced')"
check "no unit" no "$(has_unit btrfs)"

echo "=== kernel without quota_v2 ==="
run nomod -- NO_MODULE=1
check "exits 0" 0 "$(rc nomod)"
check "names the package" yes "$(has "$TMP/nomod/out" 'linux-modules-extra-')"
check "no remount" no "$(has "$(calls nomod)" 'mount ')"
check "no unit" no "$(has_unit nomod)"

echo "=== remount refused: warned, install not aborted ==="
run nomount -- MOUNT_FAILS=1
check "exits 0" 0 "$(rc nomount)"
check "no quotaon attempted" no "$(has "$(calls nomount)" 'quotaon -u')"
check "no unit" no "$(has_unit nomount)"

echo "=== quotaon fails: warned, install not aborted ==="
run qfail -- QUOTAON_FAILS=1
check "exits 0" 0 "$(rc qfail)"
check "warns limits are not enforced" yes "$(has "$TMP/qfail/out" 'will NOT be enforced')"
check "no unit" no "$(has_unit qfail)"

echo "=== the unit cannot be enabled: on now, warned about the reboot ==="
run noenable -- ENABLE_FAILS=1
check "exits 0" 0 "$(rc noenable)"
check "quota is on" on "$(quota_state noenable)"
check "warns it lasts until the next reboot" yes "$(has "$TMP/noenable/out" 'until the next reboot only')"

echo "=== no systemctl: on now, warned about the reboot ==="
prepare nosystemd
STUB_PATH="$NOSYSTEMD" go nosystemd
check "exits 0" 0 "$(rc nosystemd)"
check "quota is on" on "$(quota_state nosystemd)"
check "warns there is no systemctl" yes "$(has "$TMP/nosystemd/out" 'no systemctl')"

echo "=== /etc/fstab is never written ==="
check "no fstab path, redirect or move in the script" 0 "$(grep -cE 'FSTAB|>[^&]*fstab|(cp|mv) [^#]*fstab' "$SCRIPT")"

echo "=== wired into every host path, never fatal; the uninstaller removes the unit ==="
for f in installer.sh bootstrap-from-source.sh; do
    line=$(grep -F 'configure-quota.sh' "$SCRIPT_DIR/$f" | grep -v '^[[:space:]]*#' | head -1)
    check "$f calls configure-quota.sh" yes "$([ -n "$line" ] && echo yes || echo no)"
    check "$f tolerates its failure" yes "$([[ "$line" == *'||'* ]] && echo yes || echo no)"
done
check "int-updater.sh hands updates to updater.sh, which runs installer.sh" yes "$(has "$SCRIPT_DIR/int-updater.sh" '/updater.sh"')"
check "installer.sh has --no-quota" yes "$(has "$SCRIPT_DIR/installer.sh" '--no-quota)')"
check "bootstrap-from-source.sh has --no-quota" yes "$(has "$SCRIPT_DIR/bootstrap-from-source.sh" '--no-quota)')"
check "uninstall.sh deletes the unit" yes "$(grep -E '^[[:space:]]+rm -f ' "$SCRIPT_DIR/uninstall.sh" | grep -qF '/etc/systemd/system/panelalpha-quota.service' && echo yes || echo no)"
check "uninstall.sh runs that step" yes "$(grep -qx 'remove_boot_units' "$SCRIPT_DIR/uninstall.sh" && echo yes || echo no)"

echo
echo "passed $PASS, failed $FAIL"
[[ "$FAIL" -eq 0 ]]
