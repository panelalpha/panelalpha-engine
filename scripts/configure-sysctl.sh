#!/bin/bash
#
# Host kernel tuning for a PanelAlpha engine host.
#
# Idempotent: rewrites /etc/sysctl.d/99-panelalpha.conf and reloads it.
# Called by installer.sh, bootstrap-from-source.sh and int-updater.sh.
#
#   PANELALPHA_SYSCTL_FILE      where to write (default /etc/sysctl.d/99-panelalpha.conf)
#   PANELALPHA_SYSCTL_NO_APPLY  1 to write the file without running `sysctl --system`
#
# The two overrides exist so the file's contents can be tested without root and
# without touching a running kernel. See configure-sysctl.test.sh.

set -euo pipefail

sysctl_file="${PANELALPHA_SYSCTL_FILE:-/etc/sysctl.d/99-panelalpha.conf}"

# ---------------------------------------------------------------------------
# vm.panic_on_oom MUST be 0 on a hosting host.
#
# It was 1, beside `kernel.panic = 3`, and that pair made a busy host reboot
# itself. `panic_on_oom = 1` asks the kernel to *panic* when memory runs out
# rather than let the OOM killer pick a process; `kernel.panic = 3` then
# reboots three seconds later, without unmounting anything or stopping any
# service.
#
# Under deploy load that reboots the host again and again, each time killing
# every deploy in flight. The kernel log ends mid-sentence with no shutdown
# sequence and with no panic banner, which is what an unclean panic reboot
# leaves behind -- and `... oom-kill ...` entries in the same window show the
# host reaching the limit, not surviving it.
#
# On an appliance, 1 is a defensible choice: a wedged kernel is worse than a
# reboot. This machine runs other tenants' work. One tenant's runaway build
# taking the host down is much worse than that one build being killed, and no
# amount of restart policy, rollback or monitoring can paper over it.
#
# 0, the kernel default, lets the OOM killer pick the biggest consumer -- the
# runaway build -- and lets everything else carry on.
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# kernel.panic is kept, and is not part of the bug.
#
# It only decides how long the machine waits *after* a panic before rebooting.
# With panic_on_oom back at 0 a plain out-of-memory no longer panics, so this
# no longer fires on the path that caused the reboots -- and leaving it at 0
# would mean a host that genuinely panicked (bad driver, faulty memory) hung
# unreachable instead of coming back.
# ---------------------------------------------------------------------------

# vm.panic_on_oom is written explicitly as 0, not merely omitted. `sysctl
# --system` applies the values each file names; it does not reset anything a
# file stopped mentioning. A host that already had 1 from a previous install
# would keep it until its next reboot, which is exactly the reboot we are
# trying to stop.
cat > "$sysctl_file" <<'EOF'
# Written by PanelAlpha (scripts/configure-sysctl.sh).
#
# Re-running the installer, the bootstrap script or the updater rewrites this
# file, so change it there rather than here.
#
# vm.panic_on_oom is 0 on purpose: an out-of-memory condition must kill the
# process using the memory, not panic and reboot the host. It was 1 until
# 2026-09-14, and hosts rebooted mid-deploy because of it. See the script for
# the measurements.

# Wait 3s after a real panic, then reboot. Does not fire on a plain OOM while
# vm.panic_on_oom is 0.
kernel.panic = 3

# Let the OOM killer choose a process instead of taking the machine down.
vm.panic_on_oom = 0
EOF

echo "sysctl config written ($sysctl_file)"

if [ "${PANELALPHA_SYSCTL_NO_APPLY:-0}" != "1" ]; then
    # Apply the new sysctl settings
    sysctl --system
    echo "sysctl settings applied"
fi

echo "sysctl config applied ($sysctl_file)"
