#!/bin/bash
# Mounts the account homes nosuid,nodev: a sysbox tenant's files reach the host as 0:0, setuid kept.
# A self-bind or remount, reapplied by panelalpha-home-mounts.service at boot; fstab is never edited.
#
#   harden-home-mounts.sh          install and enable the unit, then apply; never fails
#   harden-home-mounts.sh --boot   apply only, as the unit runs it; non-zero when it could not
#
#   PANELALPHA_HOME_HARDENING=0        skip
#   PANELALPHA_HOME_PATH               directory whose filesystem is hardened (default /home)
#   PANELALPHA_SYSTEMD_DIR             where the unit is written (default /etc/systemd/system)
#   PANELALPHA_HOME_ALLOW_CONTAINER=1  run inside a container anyway (tests only)

set -uo pipefail

home_path="${PANELALPHA_HOME_PATH:-/home}"
systemd_dir="${PANELALPHA_SYSTEMD_DIR:-/etc/systemd/system}"
unit='panelalpha-home-mounts.service'
flags='nosuid,nodev'
boot=0
[ "${1:-}" = "--boot" ] && boot=1

info() { echo "home-hardening: $*"; }
warn() { echo "home-hardening: WARNING: $*" >&2; }
not_applied() {
    warn "$*"
    warn "Account homes are NOT mounted nosuid,nodev on this host."
    [ "$boot" = 1 ] && exit 1
    exit 0
}

if [ "${PANELALPHA_HOME_HARDENING:-1}" = "0" ]; then
    info "skipped (PANELALPHA_HOME_HARDENING=0); account homes will not be hardened"
    exit 0
fi

for bin in findmnt mount; do
    command -v "$bin" >/dev/null 2>&1 || not_applied "'$bin' is not installed"
done

in_container() {
    [ -f /.dockerenv ] || [ -f /run/.containerenv ] && return 0
    command -v systemd-detect-virt >/dev/null 2>&1 && systemd-detect-virt --container -q
}
if [ "${PANELALPHA_HOME_ALLOW_CONTAINER:-0}" != "1" ] && in_container; then
    not_applied "running inside a container; the homes mount belongs to the real host"
fi

[ -d "$home_path" ] || not_applied "$home_path does not exist"

has_flag() { printf '%s' "$1" | tr ',' '\n' | grep -qx "$2"; }
has_both() { has_flag "$1" nosuid && has_flag "$1" nodev; }
hardened() { has_both "$(findmnt -n -o OPTIONS --target "$home_path" 2>/dev/null)"; }

# A failure takes down the bind it made, leaving the mounts as they were.
apply() {
    local target
    target=$(findmnt -n -o TARGET --target "$home_path" 2>/dev/null)
    [ -n "$target" ] || return 1
    hardened && return 0
    if [ "$target" != "$home_path" ]; then
        mount --bind "$home_path" "$home_path" || return 1
        mount -o "remount,bind,$flags" "$home_path" || { umount "$home_path"; return 1; }
    elif [ "$(findmnt -n -r -o FSROOT --target "$home_path" 2>/dev/null)" != / ]; then
        mount -o "remount,bind,$flags" "$home_path" || return 1
    else
        mount -o "remount,$flags" "$home_path" || return 1
    fi
    hardened
}

unit_text() {
    cat <<EOF
# Written by the PanelAlpha engine (scripts/harden-home-mounts.sh).
[Unit]
Description=PanelAlpha: project homes mounted nosuid,nodev
After=local-fs.target
RequiresMountsFor=$home_path
Before=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
Environment=PANELALPHA_HOME_PATH=$home_path
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

if [ "$boot" = 1 ]; then
    apply || not_applied "mounting $home_path with $flags failed"
    info "$home_path is mounted $flags"
    exit 0
fi

until_reboot() {
    warn "$1; $home_path is hardened until the next reboot only"
    apply || not_applied "mounting $home_path with $flags failed"
    info "$home_path is mounted $flags"
    exit 0
}

command -v systemctl >/dev/null 2>&1 || until_reboot "no systemctl"
install_unit || until_reboot "could not install $unit"
systemctl restart "$unit" >/dev/null 2>&1 && hardened ||
    not_applied "$unit did not mount $home_path $flags (systemctl status $unit)"
info "$home_path is mounted $flags; $unit keeps it so at every boot"
