#!/bin/bash
set -e
cd ~/project

# Nothing in this checkout is built: the stack runs the project's own published
# image (iceshrimp.dev/iceshrimp/iceshrimp.net). The prepare hook only mints the
# secrets and hands them to compose.
#
# The secrets are generated once and live in ~/.panelalpha, which is the only
# account-owned directory that survives a rebuild (~/project is wiped and
# re-cloned every deploy, engine#173). Regenerating either value would be fatal:
# a new Postgres password locks the app out of its own data volume, and a new
# admin password would not match the one already stored in the owner's vault.
STATE=~/.panelalpha/iceshrimp
mkdir -p "$STATE"
chmod 700 ~/.panelalpha "$STATE"
SECRETS="$STATE/secrets.env"

if [ ! -f "$SECRETS" ]; then
    umask 077
    {
        printf 'ICESHRIMP_DB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'ICESHRIMP_ADMIN_USER=%s\n'  "admin"
        printf 'ICESHRIMP_ADMIN_PASSWORD=%s\n' "$(openssl rand -base64 24 | tr -d '/+=\n' | cut -c1-24)"
    } > "$SECRETS"
    chmod 600 "$SECRETS"
fi

# Copy the persistent secrets into ~/project/.env so `docker compose` can
# interpolate ${ICESHRIMP_*} from the project directory. The source of truth is
# ~/.panelalpha; this copy is rebuilt from it on every deploy.
cp "$SECRETS" .env
chmod 600 .env

echo "Iceshrimp: secrets ready in ~/.panelalpha/iceshrimp/secrets.env (admin credentials are delivered to the owner's vault)."
