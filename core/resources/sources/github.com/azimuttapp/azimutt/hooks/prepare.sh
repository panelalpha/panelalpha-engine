#!/bin/bash
# Generates the PostgreSQL password and SECRET_KEY_BASE once in ~/.panelalpha
# (survives redeploys; ~/project does not). A new key would sign out every user.
set -e
STORE="${HOME}/.panelalpha/azimutt"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    printf 'SECRET_KEY_BASE=%s\nDATABASE_URL=postgresql://azimutt:%s@database/azimutt\n' \
        "$(openssl rand -hex 48)" \
        "$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
