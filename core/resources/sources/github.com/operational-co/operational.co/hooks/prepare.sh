#!/bin/bash
# Generates the MySQL passwords and the auth SECRET once in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project
STORE="${HOME}/.panelalpha/operational"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/app.env" ]; then
    PW="$(openssl rand -hex 24)"
    (umask 077
     printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_PASSWORD=%s\n' "$(openssl rand -hex 24)" "${PW}" > "${STORE}/db.env"
     printf 'DATABASE_URL=mysql://operational:%s@mysql:3306/operational\nSECRET=%s\n' "${PW}" "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env
chmod 644 panelalpha/operational-nginx.conf
