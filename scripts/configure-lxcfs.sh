#!/bin/bash
#
# lxcfs on the host, so an account's /proc/meminfo, cpuinfo, stat, loadavg and
# diskstats show the account's limits and load rather than the host's. The
# account template binds the files from /var/lib/lxcfs/proc when they exist.
#
# Idempotent. Called by installer.sh and bootstrap-from-source.sh. Never fatal
# to them: without lxcfs accounts start as before, with the host's /proc.
#
#   PANELALPHA_SYSTEMD_DIR    where the drop-ins go (default /etc/systemd/system)
#   PANELALPHA_LXCFS_NO_APPLY 1 to write the drop-ins only: no apt, no systemctl
#
# The two overrides exist so the drop-ins can be tested without root. See
# configure-lxcfs.test.sh.

set -euo pipefail

systemd_dir="${PANELALPHA_SYSTEMD_DIR:-/etc/systemd/system}"
no_apply="${PANELALPHA_LXCFS_NO_APPLY:-0}"

lxcfs_dropin="$systemd_dir/lxcfs.service.d/panelalpha.conf"
docker_dropin="$systemd_dir/docker.service.d/panelalpha-lxcfs.conf"

# -l: per-cgroup loadavg (off by default, and loadavg is the file that shows
# neighbours' load). --enable-cfs: cpuinfo/stat follow the account's CPU quota.
# ExecStartPost holds "started" until the files exist, so docker, ordered after
# this unit, never starts an account whose bind source is still missing.
write_dropins() {
    mkdir -p "$(dirname "$lxcfs_dropin")" "$(dirname "$docker_dropin")"
    cat >"$lxcfs_dropin.tmp" <<'EOF'
# Written by PanelAlpha (scripts/configure-lxcfs.sh); re-running the installer rewrites it.
[Service]
ExecStart=
ExecStart=/usr/bin/lxcfs -l --enable-cfs /var/lib/lxcfs
ExecStartPost=/bin/sh -c 'for i in $$(seq 100); do [ -f /var/lib/lxcfs/proc/meminfo ] && exit 0; sleep 0.1; done; exit 1'
EOF
    # Accounts bind lxcfs files; restarted by docker before lxcfs mounts, they would not start.
    cat >"$docker_dropin.tmp" <<'EOF'
# Written by PanelAlpha (scripts/configure-lxcfs.sh); re-running the installer rewrites it.
[Unit]
Wants=lxcfs.service
After=lxcfs.service
EOF
    changed=0
    for f in "$lxcfs_dropin" "$docker_dropin"; do
        if cmp -s "$f.tmp" "$f"; then
            rm -f "$f.tmp"
        else
            mv "$f.tmp" "$f"
            changed=1
        fi
    done
}

write_dropins
echo "lxcfs drop-ins written ($lxcfs_dropin, $docker_dropin)"

if [ "$no_apply" = "1" ]; then
    exit 0
fi

systemctl daemon-reload

was_running=0
systemctl is-active --quiet lxcfs 2>/dev/null && was_running=1

if ! command -v lxcfs >/dev/null 2>&1; then
    # The drop-in is in place first, so the package starts lxcfs with it.
    if ! DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y lxcfs; then
        echo "Could not install lxcfs; accounts will see the host's memory, CPUs and load in /proc" >&2
        exit 1
    fi
fi

systemctl enable lxcfs >/dev/null 2>&1 || true
if [ "$was_running" = 1 ] && [ "$changed" = 1 ]; then
    # Restarting lxcfs breaks the /proc files of every running container that
    # binds them until it restarts too, so an lxcfs already serving is left alone.
    echo "lxcfs was already running; its new options apply after its next restart (a reboot)"
else
    systemctl start lxcfs
fi

if [ -f /var/lib/lxcfs/proc/meminfo ]; then
    echo "lxcfs is serving /var/lib/lxcfs/proc"
else
    echo "lxcfs is installed but /var/lib/lxcfs/proc/meminfo is missing; accounts will see the host's /proc" >&2
    exit 1
fi
