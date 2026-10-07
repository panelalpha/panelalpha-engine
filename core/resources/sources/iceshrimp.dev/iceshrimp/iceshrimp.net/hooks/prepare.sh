#!/bin/bash
set -e
cd ~/project

# Nothing in this checkout is built: the stack runs the project's own published
# image (iceshrimp.dev/iceshrimp/iceshrimp.net). The prepare hook only mints the
# secrets and hands them to compose.
#
# The secrets are generated once and live in ~/.panelalpha, which is the only
# account-owned directory that survives a rebuild (~/project is wiped and
# re-cloned every deploy). Regenerating it would be fatal: a new
# Postgres password locks the app out of its own data volume. The admin login is
# the engine's (`credentials:` in panelalpha.yaml), read by the init service from
# ~/.panelalpha/app-credentials.env.
STATE=~/.panelalpha/iceshrimp
mkdir -p "$STATE"
chmod 700 ~/.panelalpha "$STATE"
SECRETS="$STATE/secrets.env"

if [ ! -f "$SECRETS" ]; then
    umask 077
    {
        printf 'ICESHRIMP_DB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    } > "$SECRETS"
    chmod 600 "$SECRETS"
fi

# Copy the persistent secrets into ~/project/.env so `docker compose` can
# interpolate ${ICESHRIMP_*} from the project directory. The source of truth is
# ~/.panelalpha; this copy is rebuilt from it on every deploy. Older accounts
# still hold the admin login there, which stays out of .env.
grep -v '^ICESHRIMP_ADMIN_' "$SECRETS" > .env || true
chmod 600 .env

echo "Iceshrimp: secrets ready in ~/.panelalpha/iceshrimp/secrets.env (the admin login is returned by GET /projects/{name}/app-credentials)."
