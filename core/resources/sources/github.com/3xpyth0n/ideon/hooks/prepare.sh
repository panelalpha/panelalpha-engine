#!/bin/bash
# Generates the PostgreSQL password and Ideon's SECRET_KEY once, in
# ~/.panelalpha (survives redeploys; ~/project does not). SECRET_KEY derives
# the data encryption keys: a new one makes stored data unreadable.
set -e
STORE="${HOME}/.panelalpha/ideon"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    printf 'SECRET_KEY=%s\nDB_PASS=%s\n' "$(openssl rand -hex 32)" \
        "$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
