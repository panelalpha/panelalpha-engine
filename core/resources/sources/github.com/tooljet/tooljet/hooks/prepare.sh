#!/bin/bash
# Secrets generated once into ~/.panelalpha, as upstream's deploy/docker/internal.sh
# does into .env: ~/project is wiped on every deploy, the Postgres volume keeps
# its first password and LOCKBOX_MASTER_KEY encrypts stored credentials.
set -e
STORE="${HOME}/.panelalpha/tooljet"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/tooljet.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; {
        printf 'LOCKBOX_MASTER_KEY=%s\n' "$(openssl rand -hex 32)"
        printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -hex 64)"
        printf 'PGRST_JWT_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'PG_PASS=%s\nTOOLJET_DB_PASS=%s\nPOSTGRES_PASSWORD=%s\n' "$db" "$db" "$db"
        printf 'PGRST_DB_URI=postgres://postgres:%s@postgresql/tooljet_db\n' "$db"
    } > "${STORE}/tooljet.env")
fi
chmod 600 "${STORE}/tooljet.env"
