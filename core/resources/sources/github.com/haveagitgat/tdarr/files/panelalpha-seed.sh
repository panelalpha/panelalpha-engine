#!/bin/sh
# Registers Tdarr's first (admin) user as soon as the server answers, so the
# "create your account" screen is never offered to a visitor.
# Env: TDARR_ADMIN_USER, TDARR_ADMIN_PASSWORD (~/.panelalpha/tdarr/admin.env).
set -eu
UI=http://app:8265
# The media volume is created root-owned; Tdarr and its node run as PUID 1000.
chown 1000:1000 /media

body=$(printf '{"username":"%s","password":"%s"}' "$TDARR_ADMIN_USER" "$TDARR_ADMIN_PASSWORD")

# First boot chowns the data volumes before the server listens; any HTTP
# answer (auth-status is POST-only, so GET is 404) means it is up.
i=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' "$UI/api/v2/auth-status")" != 000 ]; do
    i=$((i + 1)); [ "$i" -lt 900 ] || { echo "tdarr: server never answered" >&2; exit 1; }
    sleep 1
done

code=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data "$body" "$UI/api/v2/public/auth/register")
case "$code" in
    201) echo "tdarr: admin user '$TDARR_ADMIN_USER' created" ;;
    403) echo "tdarr: a user already exists" ;;
    *) echo "tdarr: register returned HTTP $code" >&2; exit 1 ;;
esac

# Whoever exists must be ours: a user registered by someone else fails the deploy.
code=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data "$body" "$UI/api/v2/public/auth/login")
[ "$code" = 200 ] || { echo "tdarr: admin login failed (HTTP $code)" >&2; exit 1; }
echo "tdarr: admin login verified"
