#!/bin/bash
# Generate the JWT secret and the database passwords once, where a redeploy
# will not wipe them (~/project is emptied every deploy).
set -e

say() { echo "[traduora] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/traduora"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ -f "${APP_ENV}" ] && [ -f "${DB_ENV}" ]; then
    say "reusing the secrets in ${STORE_DIR}"
    exit 0
fi

DB_PASSWORD="$(rnd 24)"
(
    umask 077
    printf 'MYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' "${DB_PASSWORD}" "$(rnd 24)" > "${DB_ENV}"
    cat > "${APP_ENV}" <<ENV_EOF
# Written once; the database volume holds this password and API tokens are
# signed with TR_SECRET, so do not regenerate.
TR_SECRET=$(rnd 48)
TR_DB_PASSWORD=${DB_PASSWORD}
ENV_EOF
)
say "secrets written to ${STORE_DIR}"
