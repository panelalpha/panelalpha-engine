#!/bin/bash
# Gives xyOps's stock admin the engine's password while storage is new or the
# admin still has the default password `admin`, then runs the image's own
# CMD (bin/container-start.sh).
#
# It uses upstream's XYOPS_recover_admin: when the conductor becomes primary,
# right after it creates the initial records on a new install, xyOps writes
# users/admin again with that password. Set only for this one start, so a
# password changed in xyOps is never overwritten.
set -e
cd /opt/xyops
if [ -n "${XYOPS_ADMIN_PASSWORD:-}" ]; then
    state=$(node /panelalpha-xyops-admin.js 2>/dev/null || echo error)
    case "$state" in
        new|default)
            export XYOPS_recover_admin="admin:${XYOPS_ADMIN_PASSWORD}"
            echo "[panelalpha] xyops: the admin's login is the one the engine generated" ;;
        changed) ;;
        *) echo "[panelalpha] xyops: could not read the admin; its login is left as it is" >&2 ;;
    esac
fi
exec bash bin/container-start.sh
