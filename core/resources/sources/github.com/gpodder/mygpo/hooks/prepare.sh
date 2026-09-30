#!/bin/bash
# Generates the Django secret and the database password once in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
STORE="${HOME}/.panelalpha/mygpo"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (umask 077; {
        printf 'POSTGRES_DB=mygpo\n'
        printf 'POSTGRES_USER=mygpo\n'
        printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    } > "${STORE}/db.env")
fi
if [ ! -f "${STORE}/app.env" ]; then
    pass=$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")
    (umask 077; {
        printf 'SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
        printf 'DATABASE_URL=postgres://mygpo:%s@db:5432/mygpo\n' "${pass}"
    } > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env
