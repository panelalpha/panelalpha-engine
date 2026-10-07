#!/bin/bash
# Generates SECRET_KEY_BASE (64+ bytes) and the PostgreSQL password
# once into ~/.panelalpha/plausible-ce; the volumes keep the first values.
set -e
DIR="${HOME}/.panelalpha/plausible-ce"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "${pw}" > "${DIR}/db.env"
      printf 'SECRET_KEY_BASE=%s\nDATABASE_URL=postgres://postgres:%s@plausible_db:5432/plausible_db\n' \
        "$(openssl rand -base64 48 | tr -d '\n')" "${pw}" > "${DIR}/app.env" )
    echo "[plausible-ce] generated SECRET_KEY_BASE and the database password -> ${DIR}" >&2
fi
chmod 600 "${DIR}"/*.env
