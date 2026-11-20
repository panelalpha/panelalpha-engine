#!/bin/sh
# Run by the `ready` service once Grafana is healthy. A database Grafana made
# before the recipe had the engine's login has the built-in admin/admin, which
# GF_SECURITY_ADMIN_* no longer change (they apply to a new database only).
# While admin/admin still signs in, set the engine's login on that admin;
# otherwise do nothing, so a password changed in Grafana stays changed.
API=http://app:3000/api
[ -n "${GF_SECURITY_ADMIN_PASSWORD:-}" ] || exit 0
curl -fsS -o /dev/null -u admin:admin "$API/user" 2>/dev/null || exit 0
NAME="${GF_SECURITY_ADMIN_USER:-admin}"
if [ "$NAME" != admin ]; then
    curl -fsS -o /dev/null -u admin:admin -X PUT -H 'Content-Type: application/json' \
        -d "{\"login\":\"$NAME\"}" "$API/user" || exit 0
fi
curl -fsS -o /dev/null -u "$NAME:admin" -X PUT -H 'Content-Type: application/json' \
    -d "{\"oldPassword\":\"admin\",\"newPassword\":\"$GF_SECURITY_ADMIN_PASSWORD\",\"confirmNew\":\"$GF_SECURITY_ADMIN_PASSWORD\"}" \
    "$API/user/password" \
    && echo "[panelalpha] grafana: the admin's login is the one the engine generated" \
    || echo "[panelalpha] grafana: could not set the admin login; it stays admin/admin" >&2
exit 0
