#!/bin/bash
# Generates the database password and session secret once, in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/hedgedoc"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ]; then
    PW=$(openssl rand -hex 24)
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PW}" > "${STORE}/db.env"
     printf 'CMD_DB_URL=postgres://hedgedoc:%s@database:5432/hedgedoc\nCMD_SESSION_SECRET=%s\n' \
         "${PW}" "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
