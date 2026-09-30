#!/bin/bash
# Generates the database passwords and the JWT signing key once into
# ~/.panelalpha/sourcebans/ (~/project is wiped on every deploy; a rotated
# DB password would lock the panel out of its surviving database volume).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/sourcebans"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    DB_PASS="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' "${DB_PASS}" "$(openssl rand -hex 24)" > "${STORE}/db.env"
        # SB_SECRET_KEY: base64 of at least 32 bytes, as upstream's entrypoint requires.
        printf 'DB_PASS=%s\nSB_SECRET_KEY=%s\n' "${DB_PASS}" "$(openssl rand -base64 47 | tr -d '\n')" > "${STORE}/app.env"
    )
    echo "[sourcebans] generated secrets in ${STORE}"
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"

# The customer's env vars land in .env; make sure the file exists for env_file.
touch .env
