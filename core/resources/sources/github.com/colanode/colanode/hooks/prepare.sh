#!/bin/bash
# Generates the PostgreSQL and Valkey passwords once in ~/.panelalpha/colanode
# (survives redeploys; ~/project does not).
set -e
STORE="${HOME}/.panelalpha/colanode"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/valkey.env" ]; then
    printf 'VALKEY_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/valkey.env"
fi
if [ ! -f "${STORE}/server.env" ]; then
    DB=$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")
    VK=$(sed -n 's/^VALKEY_PASSWORD=//p' "${STORE}/valkey.env")
    printf 'POSTGRES_URL=postgres://colanode:%s@postgres:5432/colanode\nREDIS_URL=redis://:%s@valkey:6379/0\n' \
        "${DB}" "${VK}" > "${STORE}/server.env"
fi
chmod 600 "${STORE}"/*.env
