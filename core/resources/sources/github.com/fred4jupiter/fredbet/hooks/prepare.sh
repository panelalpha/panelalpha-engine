#!/bin/bash
# Generates the database and admin passwords once, in ~/.panelalpha (survives
# redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/fredbet"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ]; then
    PG_PASSWORD="$(rnd 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${STORE}/db.env"
        # FREDBET_ADMIN_PASSWORD is used only when the admin user is first created.
        printf 'SPRING_DATASOURCE_PASSWORD=%s\nFREDBET_ADMIN_USERNAME=admin\nFREDBET_ADMIN_PASSWORD=%s\n' \
            "${PG_PASSWORD}" "$(rnd 18)" > "${STORE}/app.env"
    )
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"

touch .env
