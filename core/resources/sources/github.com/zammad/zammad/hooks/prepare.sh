#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha/zammad/
# (~/project is wiped on every deploy; a new password would lock Zammad out
# of its surviving database volume).
set -e

STORE="${HOME}/.panelalpha/zammad"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    PASS="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PASS}" > "${STORE}/db.env"
        printf 'POSTGRESQL_PASS=%s\n' "${PASS}" > "${STORE}/zammad.env"
    )
    echo "[zammad] generated the database password in ${STORE}"
fi
chmod 600 "${STORE}/db.env" "${STORE}/zammad.env"
