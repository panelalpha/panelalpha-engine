#!/bin/bash
#
# Disable the host's AppArmor profiles that attach by path.
#
# A profile such as rsyslogd (/usr/sbin/rsyslogd), transmission-daemon or
# mosquitto attaches at exec to any binary at that path, whatever mount
# namespace it runs in. sysbox leaves account containers unconfined, so a
# tenant's own /usr/sbin/rsyslogd or /usr/bin/transmission-daemon ran under
# the host's distro profile and was denied the files its image expects.
#
# Disabled rather than put in complain mode, which would log every access a
# tenant makes. Kept: fusermount3 (install-sysbox.sh opens it for sysbox-fs),
# unprivileged_userns, snap-confine (it refuses to run unconfined, which would
# break every snap on the host), and profiles flagged unconfined, which confine
# nothing. Docker's docker-default is loaded by Docker and is not a file here.
#
# Idempotent. Persists the way aa-disable does, through /etc/apparmor.d/disable/,
# and a re-run on update catches profiles a newer release adds. Called by
# installer.sh and bootstrap-from-source.sh. Never fatal to them.
#
#   PANELALPHA_APPARMOR=0           skip
#   PANELALPHA_APPARMOR_DIR         profile directory (default /etc/apparmor.d)
#   PANELALPHA_APPARMOR_NO_APPLY=1  only list what would be disabled
#
# The last two exist so the selection can be tested without root, and checked
# on a host before anything changes. See configure-apparmor.test.sh.

set -uo pipefail

if [ "${PANELALPHA_APPARMOR:-1}" = "0" ]; then
    echo "Leaving the host's AppArmor profiles as they are (PANELALPHA_APPARMOR=0)"
    exit 0
fi

dir="${PANELALPHA_APPARMOR_DIR:-/etc/apparmor.d}"
no_apply="${PANELALPHA_APPARMOR_NO_APPLY:-0}"
keep=(fusermount3 unprivileged_userns usr.lib.snapd.snap-confine.real)

if [ ! -d "$dir" ]; then
    echo "No AppArmor profiles on this host ($dir is missing)"
    exit 0
fi
if [ "$no_apply" != "1" ] && ! command -v apparmor_parser >/dev/null 2>&1; then
    echo "apparmor_parser is not installed; no AppArmor profile is loaded to disable"
    exit 0
fi

# The path a profile in $1 attaches to, from its first confining header:
# `profile NAME /path ... {` or `/path ... {`. Nothing when it has none.
attachment() {
    awk '
        /^[[:space:]]*#/ { next }
        /\{[[:space:]]*$/ {
            line = $0
            sub(/^[[:space:]]+/, "", line)
            if (line ~ /^profile[[:space:]]/) {
                n = split(line, f, /[[:space:]]+/)
                path = (n >= 3) ? f[3] : ""
            } else {
                split(line, f, /[[:space:]]+/)
                path = f[1]
            }
            gsub(/"/, "", path)
            if (path !~ /^(\/|@\{)/) { next }
            if (line ~ /flags=\([^)]*unconfined/) { next }
            print path
            exit
        }
    ' "$1"
}

disabled=0
for profile in "$dir"/*; do
    [ -f "$profile" ] || continue
    name="$(basename "$profile")"
    case "$name" in *.dpkg-* | *.ucf-* | *~ | README) continue ;; esac
    for k in "${keep[@]}"; do
        [ "$name" = "$k" ] && continue 2
    done
    path="$(attachment "$profile")"
    [ -n "$path" ] || continue
    if [ -L "$dir/disable/$name" ]; then
        continue
    fi
    if [ "$no_apply" = "1" ]; then
        echo "Would disable AppArmor profile $name (attaches to $path)"
        disabled=$((disabled + 1))
        continue
    fi
    mkdir -p "$dir/disable"
    ln -sf "$profile" "$dir/disable/$name"
    # Not loaded is fine: the disable link keeps it from loading at boot.
    apparmor_parser -R "$profile" >/dev/null 2>&1 || true
    echo "Disabled AppArmor profile $name (attaches to $path)"
    disabled=$((disabled + 1))
done

if [ "$disabled" = 0 ]; then
    echo "No path-attached AppArmor profile left to disable"
fi
exit 0
