#!/bin/bash
# Generates the database password and the JWT secret once into
# ~/.panelalpha/peppermint/ (~/project is wiped every deploy).
set -e

say() { echo "[panelalpha] peppermint: $*" >&2; }

STORE="${HOME}/.panelalpha/peppermint"
DB_ENV="${STORE}/db.env"
APP_ENV="${STORE}/app.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ -f "${DB_ENV}" ] && [ -f "${APP_ENV}" ]; then
    say "reusing the secrets in ${STORE}"
    exit 0
fi

PG_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')"
(
    umask 077
    printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
    # SECRET signs session JWTs (read as base64); the image's .env default is public.
    cat > "${APP_ENV}" <<ENV_EOF
DB_PASSWORD=${PG_PASSWORD}
DATABASE_URL=postgresql://peppermint:${PG_PASSWORD}@db:5432/peppermint
SECRET=$(openssl rand -base64 48 | tr -d '\n')
ENV_EOF
)
say "secrets written to ${STORE}"
