#!/bin/bash
# Secrets generated once into ~/.panelalpha (~/project is wiped on every deploy):
# the Postgres/Redis passwords stay bound to their volumes, SYSTEM_AES_KEY
# (exactly 32 chars) encrypts stored model API keys, JWT_SECRET signs sessions.
set -e
STORE="${HOME}/.panelalpha/weknora"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    db="$(openssl rand -hex 24)"
    redis="$(openssl rand -hex 24)"
    (umask 077; {
        printf 'POSTGRES_PASSWORD=%s\n' "$db"
        printf 'DB_PASSWORD=%s\n' "$db"
        printf 'REDIS_PASSWORD=%s\n' "$redis"
        printf 'SYSTEM_AES_KEY=%s\n' "$(openssl rand -hex 16)"
        printf 'SYSTEM_SIGNING_KEY=%s\n' "$(openssl rand -hex 32)"
        printf 'JWT_SECRET=%s\n' "$(openssl rand -hex 32)"
    } > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"
