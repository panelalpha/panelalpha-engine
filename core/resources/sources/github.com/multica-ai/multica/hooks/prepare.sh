#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates the Postgres
# password and JWT secret once into ~/.panelalpha (a redeploy empties ~/project).
set -e

say() { echo "[multica] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/multica"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -hex "$1"; }

if [ ! -f "${APP_ENV}" ]; then
    PG_PASSWORD="$(rnd 24)"
    (
        umask 077
        cat > "${APP_ENV}" <<ENV
# Written once on the first deploy and reused on every redeploy.
POSTGRES_PASSWORD=${PG_PASSWORD}
DATABASE_URL=postgres://multica:${PG_PASSWORD}@postgres:5432/multica?sslmode=disable
JWT_SECRET=$(rnd 32)
ENV
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi
