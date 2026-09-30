#!/bin/bash
# Generates the Postgres password and the Django SECRET_KEY once in
# ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project
STORE="${HOME}/.panelalpha/socialhome"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PW}" > "${STORE}/db.env"
     printf 'DATABASE_URL=postgres://socialhome:%s@db:5432/socialhome\nDJANGO_SECRET_KEY=%s\n' "${PW}" "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env
chmod 644 panelalpha/socialhome-nginx.conf
