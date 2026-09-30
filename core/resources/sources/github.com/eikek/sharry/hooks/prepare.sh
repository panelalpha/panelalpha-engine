#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha/sharry/ (survives
# redeploys; ~/project is wiped every deploy).
set -e
STORE="${HOME}/.panelalpha/sharry"
DB_ENV="${STORE}/db.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${DB_ENV}" ]; then
    ( umask 077; printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${DB_ENV}" )
    echo "[panelalpha] sharry: generated the database password" >&2
fi
chmod 600 "${DB_ENV}"
