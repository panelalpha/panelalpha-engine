#!/bin/bash
# Generates the database/Redis passwords and the app secrets once, in
# ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/ghostfolio"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ]; then
    PG_PASS=$(openssl rand -hex 16)
    REDIS_PASS=$(openssl rand -hex 16)
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASS}" > "${STORE}/db.env"
     printf 'REDIS_PASSWORD=%s\n' "${REDIS_PASS}" > "${STORE}/redis.env"
     printf 'DATABASE_URL=postgresql://ghostfolio:%s@postgres:5432/ghostfolio?connect_timeout=300\nREDIS_PASSWORD=%s\nACCESS_TOKEN_SALT=%s\nJWT_SECRET_KEY=%s\n' \
         "${PG_PASS}" "${REDIS_PASS}" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env
