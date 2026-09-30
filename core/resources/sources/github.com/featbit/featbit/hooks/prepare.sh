#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates the Postgres
# password and JWT signing key once into ~/.panelalpha (a redeploy empties ~/project).
set -e
cd ~/project

say() { echo "[featbit] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/featbit"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -base64 48 | tr -d '\n=/+' | cut -c1-"$1"; }

if [ ! -f "${APP_ENV}" ]; then
    PG_PASSWORD="$(rnd 32)"
    JWT_KEY="$(rnd 64)"
    CONN="Host=postgresql;Port=5432;Username=postgres;Password=${PG_PASSWORD};Database=featbit"
    (
        umask 077
        cat > "${APP_ENV}" <<ENV
# Written once on the first deploy and reused on every redeploy.
POSTGRES_PASSWORD=${PG_PASSWORD}
Postgres__ConnectionString=${CONN}
Jwt__Key=${JWT_KEY}
ENV
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi
