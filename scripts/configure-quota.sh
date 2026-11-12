#!/bin/bash
#
# Turn on user quota for the filesystem that holds /home, so the `setquota` in
# Project::configureQuota() is enforced. Without this the engine records
# disk_space_limit / inodes_limit and nothing on the host applies them.
#
# fstab is not edited: panelalpha-quota.service turns quota on at boot, before Docker.
#
# Idempotent, and never fails an install: where quota cannot be turned on it
# says why and exits 0.
#
#   configure-quota.sh          turn quota on and install the unit
#   configure-quota.sh --boot   what the unit runs; non-zero when quota stays off
#
#   PANELALPHA_QUOTA=0                  skip (installer: --no-quota)
#   PANELALPHA_QUOTA_PATH               directory whose filesystem gets quota (default /home)
#   PANELALPHA_SYSTEMD_DIR              where the unit is written (default /etc/systemd/system)
#   PANELALPHA_QUOTA_ALLOW_CONTAINER=1  run inside a container anyway (tests only)
#
# ext4: a remount with `usrjquota=aquota.user,jqfmt=vfsv1`, quotacheck, quotaon.
# XFS turns quota on only at mount time, so it is reported, not changed.

set -uo pipefail

quota_path="${PANELALPHA_QUOTA_PATH:-/home}"
systemd_dir="${PANELALPHA_SYSTEMD_DIR:-/etc/systemd/system}"
unit='panelalpha-quota.service'
jopts='usrjquota=aquota.user,jqfmt=vfsv1'
boot=0
[ "${1:-}" = "--boot" ] && boot=1

info() { echo "quota: $*"; }
warn() { echo "quota: WARNING: $*" >&2; }
not_enforced() {
    warn "$*"
    warn "Disk and inode limits set on projects will NOT be enforced on this host."
    [ "$boot" = 1 ] && exit 1
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

# Quota tools take only a filesystem root's mount, so a subtree bind is followed to it.
filesystem_root_mount() {
    local path="$1" mount fsroot device root
    mount=$(findmnt -n -o TARGET --target "$path" 2>/dev/null)
    fsroot=$(findmnt -n -r -o FSROOT --target "$path" 2>/dev/null)
    device=$(findmnt -n -r -o MAJ:MIN --target "$path" 2>/dev/null)
    if [ -n "$mount" ] && [ -n "$fsroot" ] && [ "$fsroot" != / ] && [ -n "$device" ]; then
        root=$(findmnt -n -r -o TARGET,FSROOT,MAJ:MIN 2>/dev/null | awk -v d="$device" -v p="$path" '
            $2 == "/" && $3 == d && (p == $1 || $1 == "/" || index(p, $1 "/") == 1) { print $1; exit }')
        [ -n "$root" ] && mount="$root"
    fi
    echo "$mount"
}

target=$(filesystem_root_mount "$quota_path")
source=$(findmnt -n -o SOURCE --target "$target" 2>/dev/null)
fstype=$(findmnt -n -o FSTYPE --target "$target" 2>/dev/null)
[ -n "$target" ] || not_enforced "cannot find the filesystem holding $quota_path"

# Not a pipe: quotaon -p exits non-zero when quota IS on, which pipefail
# would turn into "off".
quota_is_on() {
    local state
    state=$(quotaon -p -u "$target" 2>/dev/null)
    [[ "$state" == *"is on"* ]]
}

mounted_with_quota() {
    findmnt -n -o OPTIONS --target "$target" 2>/dev/null | tr ',' '\n' | grep -qE '^(usrquota|usrjquota=.*|quota)$'
}

supported_filesystem() {
    case "$fstype" in
    ext4 | ext3) ;;
    xfs)
        if [ "$target" = "/" ]; then
            not_enforced "$target is XFS; quota can only be turned on at boot. Add 'rootflags=uquota' to GRUB_CMDLINE_LINUX in /etc/default/grub, run update-grub and reboot."
        fi
        not_enforced "$target is XFS; quota can only be turned on at mount time. Add 'uquota' to its options in /etc/fstab and remount it (or reboot)."
        ;;
    *)
        not_enforced "$target is $fstype; only ext4 (turned on here) and XFS (by hand) support user quota"
        ;;
    esac
}

# The ext4 quota feature: usage is kept by the filesystem itself and only
# needs switching on.
has_quota_feature() {
    command -v dumpe2fs >/dev/null 2>&1 &&
        dumpe2fs -h "$source" 2>/dev/null | grep -i '^Filesystem features' | grep -qw quota
}

# vfsv1 is served by quota_v2. Cloud kernels (linux-kvm and friends) can ship
# without it; it lives in linux-modules-extra-$(uname -r) there.
has_quota_v2() {
    if command -v modprobe >/dev/null 2>&1; then
        modprobe quota_v2 >/dev/null 2>&1
    else
        [ -d /sys/module/quota_v2 ]
    fi
}

# -m: the filesystem is live, so it is not remounted read-only.
count_usage() {
    local create=c
    [ -f "${target%/}/aquota.user" ] && create=''
    quotacheck -"$create"umF vfsv1 "$target" || warn "quotacheck on $target reported errors; continuing"
}

turn_on() {
    supported_filesystem
    if has_quota_feature; then
        quotaon -u "$target" >/dev/null 2>&1 || true
        quota_is_on || not_enforced "$target has the ext4 quota feature but quotaon failed"
        return
    fi
    has_quota_v2 || not_enforced "the running kernel has no quota_v2 module (try: apt-get install linux-modules-extra-$(uname -r), then re-run this script)"
    if ! mounted_with_quota; then
        mount -o "remount,$jopts" "$target" || not_enforced "remounting $target with $jopts failed"
    fi
    count_usage
    quotaon -u "$target" 2>&1 | grep -v 'Cannot stat() mounted device tmpfs' >&2
    quota_is_on || not_enforced "quotaon did not turn user quota on for $target"
}

unit_text() {
    cat <<EOF
# Written by the PanelAlpha engine (scripts/configure-quota.sh).
[Unit]
Description=PanelAlpha: user quota on for the project homes
After=local-fs.target systemd-quotacheck.service quotaon.service
RequiresMountsFor=$quota_path
Before=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
Environment=PANELALPHA_QUOTA_PATH=$quota_path
ExecStart=/bin/bash $1 --boot

[Install]
WantedBy=docker.service multi-user.target
EOF
}

install_unit() {
    local file="$systemd_dir/$unit" self text
    self="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
    text=$(unit_text "$self")
    if [ "$(cat "$file" 2>/dev/null)" != "$text" ]; then
        printf '%s\n' "$text" >"$file" || return 1
        systemctl daemon-reload || return 1
    fi
    systemctl enable "$unit" >/dev/null 2>&1
}

keep_on_at_boot() {
    if ! command -v systemctl >/dev/null 2>&1; then
        warn "no systemctl: quota stays on until the next reboot only"
    elif ! install_unit; then
        warn "could not install $unit; quota stays on until the next reboot only"
    elif ! systemctl start "$unit" >/dev/null 2>&1; then
        warn "$unit did not start (systemctl status $unit)"
    else
        info "$unit turns it on at every boot, before Docker"
    fi
}

if [ "$boot" = 1 ]; then
    quota_is_on && { info "user quota already on for $target"; exit 0; }
    turn_on
    info "user quota turned on for $target ($source, $fstype)"
    exit 0
fi

if quota_is_on; then
    info "user quota already on for $target ($source, $fstype)"
else
    turn_on
    info "user quota turned on for $target ($source, $fstype)"
    info "Limits already stored on projects apply the next time they are saved, or now with: pae-artisan project:quota:rebuild --all"
fi
keep_on_at_boot
exit 0
