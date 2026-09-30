#!/bin/bash
# Generates the Postgres password once in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
STORE="${HOME}/.panelalpha/flowfuse"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/forge.env" ]; then
    PW="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PW}" > "${STORE}/db.env"
     printf 'FF_DB_PASSWORD=%s\n' "${PW}" > "${STORE}/forge.env")
fi
chmod 600 "${STORE}"/*.env
