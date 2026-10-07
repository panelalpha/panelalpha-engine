#!/bin/bash
#
# Turn on user quota for the filesystem that holds /home, so the `setquota` in
# Project::configureQuota() is enforced. Without this the engine records
# disk_space_limit / inodes_limit and nothing on the host applies them.
#
# Idempotent, and never fails an install: where quota cannot be turned on it
# says why and exits 0. Called by installer.sh, bootstrap-from-source.sh and
# int-updater.sh.
#
#   PANELALPHA_QUOTA=0                  skip (installer: --no-quota)
#   PANELALPHA_QUOTA_PATH               directory whose filesystem gets quota (default /home)
#   PANELALPHA_FSTAB                    fstab to edit (default /etc/fstab)
#   PANELALPHA_QUOTA_ALLOW_CONTAINER=1  run inside a container anyway (tests only)
#
# ext4 gets journaled user quota: `usrjquota=aquota.user,jqfmt=vfsv1` in fstab,
# a remount, quotacheck, quotaon. `tune2fs -O quota` would be cleaner but only
# works on an unmounted filesystem, which / never is. XFS can only turn quota on
# at mount time (root: `rootflags=uquota` on the kernel command line), so it is
# reported with instructions, not changed.

set -uo pipefail

quota_path="${PANELALPHA_QUOTA_PATH:-/home}"
fstab="${PANELALPHA_FSTAB:-/etc/fstab}"
jopts='usrjquota=aquota.user,jqfmt=vfsv1'

info() { echo "quota: $*"; }
warn() { echo "quota: WARNING: $*" >&2; }
not_enforced() {
    warn "$*"
    warn "Disk and inode limits set on projects will NOT be enforced on this host."
    exit 0
}

if [ "${PANELALPHA_QUOTA:-1}" = "0" ]; then
    info "skipped (PANELALPHA_QUOTA=0 / --no-quota); project disk limits will not be enforced"
    exit 0
fi

for bin in findmnt quotaon quotacheck setquota; do
    command -v "$bin" >/dev/null 2>&1 || not_enforced "'$bin' is not installed (apt-get install quota)"
done

in_container() {
    [ -f /.dockerenv ] || [ -f /run/.containerenv ] && return 0
    command -v systemd-detect-virt >/dev/null 2>&1 && systemd-detect-virt --container -q
}
if [ "${PANELALPHA_QUOTA_ALLOW_CONTAINER:-0}" != "1" ] && in_container; then
    not_enforced "running inside a container; quota belongs to the real host's filesystem"
fi

target=$(findmnt -n -o TARGET --target "$quota_path" 2>/dev/null)
source=$(findmnt -n -o SOURCE --target "$quota_path" 2>/dev/null)
fstype=$(findmnt -n -o FSTYPE --target "$quota_path" 2>/dev/null)
[ -n "$target" ] || not_enforced "cannot find the filesystem holding $quota_path"

# Not a pipe: quotaon -p exits non-zero when quota IS on, which pipefail
# would turn into "off".
quota_is_on() {
    local state
    state=$(quotaon -p -u "$target" 2>/dev/null)
    [[ "$state" == *"is on"* ]]
}

if quota_is_on; then
    info "user quota already on for $target ($source, $fstype)"
    exit 0
fi

case "$fstype" in
ext4 | ext3) ;;
xfs)
    if [ "$target" = "/" ]; then
        not_enforced "$target is XFS; quota can only be turned on at boot. Add 'rootflags=uquota' to GRUB_CMDLINE_LINUX in /etc/default/grub, run update-grub and reboot."
    fi
    not_enforced "$target is XFS; quota can only be turned on at mount time. Add 'uquota' to its options in $fstab and remount it (or reboot)."
    ;;
*)
    not_enforced "$target is $fstype; only ext4 (turned on here) and XFS (by hand) support user quota"
    ;;
esac

# The ext4 quota feature: usage is kept by the filesystem itself and only
# needs switching on.
if command -v dumpe2fs >/dev/null 2>&1 &&
    dumpe2fs -h "$source" 2>/dev/null | grep -i '^Filesystem features' | grep -qw quota; then
    quotaon -u "$target" >/dev/null 2>&1 || true
    quota_is_on && { info "user quota turned on for $target (ext4 quota feature)"; exit 0; }
    not_enforced "$target has the ext4 quota feature but quotaon failed"
fi

# vfsv1 is served by quota_v2. Cloud kernels (linux-kvm and friends) can ship
# without it; it lives in linux-modules-extra-$(uname -r) there.
if command -v modprobe >/dev/null 2>&1; then
    modprobe quota_v2 >/dev/null 2>&1 && have_v2=1 || have_v2=0
else
    [ -d /sys/module/quota_v2 ] && have_v2=1 || have_v2=0
fi
if [ "$have_v2" != 1 ]; then
    not_enforced "the running kernel has no quota_v2 module (try: apt-get install linux-modules-extra-$(uname -r), then re-run this script)"
fi

# Persist before touching the live mount, so a reboot gets the same state.
# Only field 4 of the one line for $target changes; anything else is refused.
entry_opts=$(awk -v t="$target" '$1 !~ /^#/ && $2 == t { print $4; exit }' "$fstab" 2>/dev/null)
[ -n "$entry_opts" ] || not_enforced "no $fstab line mounts $target, so quota would not survive a reboot"

backup=''
if ! printf '%s' "$entry_opts" | tr ',' '\n' | grep -qE '^(usrquota|usrjquota=.*|quota)$'; then
    backup="${fstab}.panelalpha-$(date +%Y%m%d%H%M%S).bak"
    cp -p "$fstab" "$backup" || not_enforced "cannot back up $fstab"
    tmp=$(mktemp "${fstab}.XXXXXX") || not_enforced "cannot write next to $fstab"
    awk -v t="$target" -v add="$jopts" '
        $1 !~ /^#/ && $2 == t && !done { $4 = $4 "," add; done = 1 }
        { print }
    ' "$backup" >"$tmp"
    changed=$(diff "$backup" "$tmp" | grep -c '^[<>]')
    lines_before=$(wc -l <"$backup")
    lines_after=$(wc -l <"$tmp")
    if [ "$changed" != "2" ] || [ "$lines_before" != "$lines_after" ]; then
        rm -f "$tmp" "$backup"
        not_enforced "refusing an fstab edit that changes more than the $target line"
    fi
    # findmnt --verify is only a gate if the original passes it.
    if findmnt --verify --tab-file "$backup" >/dev/null 2>&1 &&
        ! findmnt --verify --tab-file "$tmp" >/dev/null 2>&1; then
        rm -f "$tmp" "$backup"
        not_enforced "the edited $fstab does not verify; left unchanged"
    fi
    chmod --reference="$backup" "$tmp" 2>/dev/null
    mv "$tmp" "$fstab"
    info "added $jopts to $target in $fstab (backup: $backup)"
fi

restore_fstab() {
    [ -n "$backup" ] && cp -p "$backup" "$fstab" && warn "$fstab restored from $backup"
}

current_opts=$(findmnt -n -o OPTIONS --target "$quota_path")
if ! printf '%s' "$current_opts" | tr ',' '\n' | grep -qE '^(usrquota|usrjquota=.*|quota)$'; then
    if ! mount -o "remount,$jopts" "$target"; then
        restore_fstab
        not_enforced "remounting $target with $jopts failed"
    fi
fi

# -m: do not remount read-only, the filesystem is live. On a busy host the
# first count can be slightly off; the kernel keeps it exact from quotaon on.
quotacheck -cumF vfsv1 "$target" || warn "quotacheck on $target reported errors; continuing"
quotaon -u "$target" 2>&1 | grep -v 'Cannot stat() mounted device tmpfs' >&2

if quota_is_on; then
    info "user quota turned on for $target ($source, $fstype)"
    info "Limits already stored on projects apply the next time they are saved, or now with: pae-artisan project:quota:rebuild --all"
    exit 0
fi
not_enforced "quotaon did not turn user quota on for $target"
