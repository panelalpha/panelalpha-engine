#!/bin/bash
# Generate the database password and the session secret once, where a redeploy
# will not wipe them (~/project is emptied every deploy).
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/codimd"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    PG_PASSWORD="$(rnd 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
        cat > "${APP_ENV}" <<ENV_EOF
# Written once: the database volume holds this password and sessions are
# signed with the secret, so do not regenerate.
CMD_DB_URL=postgres://codimd:${PG_PASSWORD}@database:5432/codimd
CMD_SESSION_SECRET=$(rnd 48)
ENV_EOF
    )
    echo "[codimd] secrets written to ${STORE_DIR}" >&2
else
    echo "[codimd] reusing the secrets in ${STORE_DIR}" >&2
fi

touch .env
