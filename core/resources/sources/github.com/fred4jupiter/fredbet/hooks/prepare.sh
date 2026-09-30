#!/bin/bash
# Generates the database password once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The admin login is the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this hook.
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
        printf 'SPRING_DATASOURCE_PASSWORD=%s\n' "${PG_PASSWORD}" > "${STORE}/app.env"
    )
fi
# An older deploy kept the login here too; the engine adopted it.
sed -i '/^FREDBET_ADMIN_\(USERNAME\|PASSWORD\)=/d' "${STORE}/app.env"
chmod 600 "${STORE}/db.env" "${STORE}/app.env"

touch .env
