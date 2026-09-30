#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha/homechart
# (the database volume keeps the first one, so it must never change).
set -e
DIR="${HOME}/.panelalpha/homechart"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "${pw}" > "${DIR}/db.env"
      printf 'homechart_database_uri=postgresql://homechart:%s@postgres:5432/homechart?sslmode=disable\n' "${pw}" > "${DIR}/app.env" )
    echo "[homechart] generated the database password -> ${DIR}" >&2
fi
chmod 600 "${DIR}"/*.env
