#!/bin/bash
# Generates this account's secrets once into ~/.panelalpha/liberaforms (a
# deploy wipes ~/project) and reuses them on every redeploy.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/liberaforms"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    # CRYPTO_KEY is a Fernet key (32 bytes, url-safe base64); losing it makes
    # encrypted form data unreadable, so it is never regenerated.
    CRYPTO_KEY="$(openssl rand -base64 32 | tr '+/' '-_')"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${DB_PASSWORD}" > "${STORE}/db.env"
        printf 'DB_PASSWORD=%s\nSECRET_KEY=%s\nCRYPTO_KEY=%s\n' \
            "${DB_PASSWORD}" "$(openssl rand -hex 32)" "${CRYPTO_KEY}" > "${STORE}/app.env"
    )
    echo "[liberaforms] secrets written to ${STORE}" >&2
fi

touch .env
