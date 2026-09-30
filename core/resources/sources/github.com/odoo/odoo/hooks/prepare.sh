#!/bin/bash
# The database password must stay stable across deploys (the postgres volume
# keeps the first one), so it lives in ~/.panelalpha, not ~/project.
set -e
STORE_DIR="${HOME}/.panelalpha/odoo"
DB_ENV="${STORE_DIR}/db.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${DB_ENV}" ]; then
    PW=$(openssl rand -hex 24)
    # POSTGRES_PASSWORD for the postgres image, PASSWORD for Odoo's entrypoint.
    (umask 077; printf 'POSTGRES_PASSWORD=%s\nPASSWORD=%s\n' "${PW}" "${PW}" > "${DB_ENV}")
    echo "[odoo] generated the database password in ${DB_ENV}" >&2
else
    echo "[odoo] reusing ${DB_ENV}" >&2
fi
