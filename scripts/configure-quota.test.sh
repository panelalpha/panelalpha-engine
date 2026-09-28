#!/bin/bash
#
# configure-quota.sh against stubbed findmnt/quotaon/mount, without root and
# without touching a real filesystem. The real run, against a loopback ext4,
# is recorded in the MR for #244.
#
#   bash scripts/configure-quota.test.sh
#
# The regression this guards (#244): the installer installed the `quota`
# package and nothing ever turned quota on, so every setquota the engine ran
# was a no-op and no disk limit was enforced.

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
has() { grep -qF -- "$2" "$1" && echo yes || echo no; }

# Stubs. Every call is appended to $TMP/calls; state lives in $TMP/state.
BIN="$TMP/bin"
mkdir -p "$BIN"
cat >"$BIN/findmnt" <<'EOF'
#!/bin/bash
echo "findmnt $*" >>"$STUB_DIR/calls"
[[ " $* " == *" --verify "* ]] && exit 0
case "$3" in
TARGET) echo "$FM_TARGET" ;;
SOURCE) echo "/dev/sda1" ;;
FSTYPE) echo "$FM_FSTYPE" ;;
OPTIONS) echo "$FM_OPTIONS" ;;
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
for cmd in quotacheck setquota dumpe2fs; do
    printf '#!/bin/bash\necho "%s $*" >>"$STUB_DIR/calls"\n' "$cmd" >"$BIN/$cmd"
done
cat >"$BIN/mount" <<'EOF'
#!/bin/bash
echo "mount $*" >>"$STUB_DIR/calls"
[ -z "${MOUNT_FAILS:-}" ]
EOF
cat >"$BIN/modprobe" <<'EOF'
#!/bin/bash
echo "modprobe $*" >>"$STUB_DIR/calls"
[ -z "${NO_MODULE:-}" ]
EOF
cat >"$BIN/systemd-detect-virt" <<'EOF'
#!/bin/bash
[ -n "${IN_CONTAINER:-}" ]
EOF
chmod +x "$BIN"/*

FSTAB_ORIG="$TMP/fstab.orig"
cat >"$FSTAB_ORIG" <<'EOF'
# /etc/fstab: static file system information.
/dev/disk/by-uuid/9e4ec673-4345-4852-8a69-0f7f3df29989	/	ext4	defaults	0 1
/dev/disk/by-uuid/A948-365C	/boot/efi	vfat	defaults	0 1
/dev/sdb1	/home	ext4	defaults,noatime	0 2
EOF

# run <name> [VAR=value ...]: fresh fstab and state, then the script.
run() {
    local name="$1"
    shift
    export STUB_DIR="$TMP/$name"
    mkdir -p "$STUB_DIR"
    cp "$FSTAB_ORIG" "$STUB_DIR/fstab"
    : >"$STUB_DIR/calls"
    env PATH="$BIN:$PATH" STUB_DIR="$STUB_DIR" PANELALPHA_FSTAB="$STUB_DIR/fstab" \
        PANELALPHA_QUOTA_ALLOW_CONTAINER=1 FM_TARGET=/ FM_FSTYPE=ext4 FM_OPTIONS=rw,relatime \
        "$@" bash "$SCRIPT_DIR/configure-quota.sh" >"$STUB_DIR/out" 2>&1
    echo $? >"$STUB_DIR/rc"
}
rc() { cat "$TMP/$1/rc"; }
fstab_line() { awk -v t="$2" '$1 !~ /^#/ && $2 == t' "$TMP/$1/fstab"; }
unchanged() { cmp -s "$FSTAB_ORIG" "$TMP/$1/fstab" && echo yes || echo no; }
backups() { find "$TMP/$1" -name 'fstab.panelalpha-*.bak' | wc -l; }

echo "=== ext4 root, quota off: turned on and persisted ==="
run fresh
check "exits 0" 0 "$(rc fresh)"
check "/ gets journaled user quota in fstab" \
    "/dev/disk/by-uuid/9e4ec673-4345-4852-8a69-0f7f3df29989 / ext4 defaults,usrjquota=aquota.user,jqfmt=vfsv1 0 1" \
    "$(fstab_line fresh /)"
check "every other fstab line is byte-identical" "3" \
    "$(grep -cxF -f <(grep -v -P '\t/\t' "$FSTAB_ORIG") "$TMP/fresh/fstab")"
check "a backup of fstab was kept" 1 "$(backups fresh)"
check "live remount with the same options" yes "$(has "$TMP/fresh/calls" 'mount -o remount,usrjquota=aquota.user,jqfmt=vfsv1 /')"
check "quotacheck builds aquota.user" yes "$(has "$TMP/fresh/calls" 'quotacheck -cumF vfsv1 /')"
check "quotaon -u /" yes "$(has "$TMP/fresh/calls" 'quotaon -u /')"
check "says it is on" yes "$(has "$TMP/fresh/out" 'user quota turned on for /')"
check "points at stored limits" yes "$(has "$TMP/fresh/out" 'project:quota:rebuild --all')"

echo "=== already on: nothing touched (quotaon -p exits 1 when on) ==="
run again
echo on >"$TMP/again/state"
: >"$TMP/again/calls"
cp "$FSTAB_ORIG" "$TMP/again/fstab"
env PATH="$BIN:$PATH" STUB_DIR="$TMP/again" PANELALPHA_FSTAB="$TMP/again/fstab" PANELALPHA_QUOTA_ALLOW_CONTAINER=1 \
    FM_TARGET=/ FM_FSTYPE=ext4 FM_OPTIONS=rw bash "$SCRIPT_DIR/configure-quota.sh" >"$TMP/again/out" 2>&1
check "exits 0" 0 "$?"
check "reports already on" yes "$(has "$TMP/again/out" 'already on')"
check "fstab unchanged" yes "$(unchanged again)"
check "no remount" no "$(has "$TMP/again/calls" 'mount ')"
check "no quotacheck on a live quota" no "$(has "$TMP/again/calls" 'quotacheck')"

echo "=== fstab already has it, quota off (e.g. after a failed boot quotaon) ==="
run halfway
sed -i 's#\t/\text4\tdefaults#\t/\text4\tdefaults,usrjquota=aquota.user,jqfmt=vfsv1#' "$TMP/halfway/fstab"
cp "$TMP/halfway/fstab" "$TMP/halfway/fstab.before"
rm -f "$TMP/halfway/state"
: >"$TMP/halfway/calls"
env PATH="$BIN:$PATH" STUB_DIR="$TMP/halfway" PANELALPHA_FSTAB="$TMP/halfway/fstab" PANELALPHA_QUOTA_ALLOW_CONTAINER=1 \
    FM_TARGET=/ FM_FSTYPE=ext4 FM_OPTIONS=rw,usrjquota=aquota.user,jqfmt=vfsv1 \
    bash "$SCRIPT_DIR/configure-quota.sh" >"$TMP/halfway/out" 2>&1
check "exits 0" 0 "$?"
check "option not added twice" yes "$(cmp -s "$TMP/halfway/fstab" "$TMP/halfway/fstab.before" && echo yes || echo no)"
check "no remount needed" no "$(has "$TMP/halfway/calls" 'mount -o remount')"
check "quotaon ran" yes "$(has "$TMP/halfway/calls" 'quotaon -u /')"

echo "=== /home on its own filesystem: only that line changes ==="
run home FM_TARGET=/home
check "exits 0" 0 "$(rc home)"
check "/home line" "/dev/sdb1 /home ext4 defaults,noatime,usrjquota=aquota.user,jqfmt=vfsv1 0 2" "$(fstab_line home /home)"
check "/ line untouched" "$(fstab_line fresh /boot/efi)" "$(fstab_line home /boot/efi)"
check "/ has no quota option" no "$(fstab_line home / | grep -q usrjquota && echo yes || echo no)"

echo "=== opt-out ==="
run optout PANELALPHA_QUOTA=0
check "exits 0" 0 "$(rc optout)"
check "fstab unchanged" yes "$(unchanged optout)"
check "nothing ran" "0" "$(wc -l <"$TMP/optout/calls")"

echo "=== inside a container ==="
run container PANELALPHA_QUOTA_ALLOW_CONTAINER=0 IN_CONTAINER=1
check "exits 0" 0 "$(rc container)"
check "fstab unchanged" yes "$(unchanged container)"
check "says why" yes "$(has "$TMP/container/out" 'inside a container')"

echo "=== XFS: instructions, no change ==="
run xfs FM_FSTYPE=xfs
check "exits 0" 0 "$(rc xfs)"
check "fstab unchanged" yes "$(unchanged xfs)"
check "names rootflags for a root XFS" yes "$(has "$TMP/xfs/out" 'rootflags=uquota')"
check "no remount" no "$(has "$TMP/xfs/calls" 'mount ')"

echo "=== btrfs / overlay: skipped ==="
run btrfs FM_FSTYPE=btrfs
check "exits 0" 0 "$(rc btrfs)"
check "fstab unchanged" yes "$(unchanged btrfs)"
check "warns limits are not enforced" yes "$(has "$TMP/btrfs/out" 'will NOT be enforced')"

echo "=== kernel without quota_v2 ==="
run nomod NO_MODULE=1
check "exits 0" 0 "$(rc nomod)"
check "fstab unchanged" yes "$(unchanged nomod)"
check "names the package" yes "$(has "$TMP/nomod/out" 'linux-modules-extra-')"

echo "=== remount refused: fstab restored ==="
run nomount MOUNT_FAILS=1
check "exits 0" 0 "$(rc nomount)"
check "fstab back to the original" yes "$(unchanged nomount)"
check "no quotaon attempted" no "$(has "$TMP/nomount/calls" 'quotaon -u')"

echo "=== no fstab line for the mount ==="
run noline FM_TARGET=/srv
check "exits 0" 0 "$(rc noline)"
check "fstab unchanged" yes "$(unchanged noline)"

echo "=== quotaon fails: warned, install not aborted ==="
run qfail QUOTAON_FAILS=1
check "exits 0" 0 "$(rc qfail)"
check "warns limits are not enforced" yes "$(has "$TMP/qfail/out" 'will NOT be enforced')"

echo "=== wired into every host path, never fatal ==="
for f in installer.sh int-updater.sh bootstrap-from-source.sh; do
    line=$(grep -F 'configure-quota.sh' "$SCRIPT_DIR/$f" | grep -v '^[[:space:]]*#' | head -1)
    check "$f calls configure-quota.sh" yes "$([ -n "$line" ] && echo yes || echo no)"
    check "$f tolerates its failure" yes "$([[ "$line" == *'||'* ]] && echo yes || echo no)"
done
check "installer.sh has --no-quota" yes "$(has "$SCRIPT_DIR/installer.sh" '--no-quota)')"
check "bootstrap-from-source.sh has --no-quota" yes "$(has "$SCRIPT_DIR/bootstrap-from-source.sh" '--no-quota)')"

echo
echo "passed $PASS, failed $FAIL"
[[ "$FAIL" -eq 0 ]]
