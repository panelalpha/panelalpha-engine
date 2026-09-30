#!/bin/bash
# Generates APP_KEY and the database password once, in ~/.panelalpha (survives
# redeploys; ~/project does not). The image refuses to start without APP_KEY.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/lychee"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/db.env" ]; then
    pw=$(openssl rand -hex 24)
    (umask 077
     printf 'APP_KEY=base64:%s\nDB_PASSWORD=%s\n' "$(openssl rand -base64 32)" "$pw" > "${STORE}/app.env"
     printf 'MARIADB_PASSWORD=%s\n' "$pw" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/app.env" "${STORE}/db.env"
