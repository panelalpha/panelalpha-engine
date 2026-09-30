#!/bin/bash
set -e
cd ~/project

# Secrets live in ~/.panelalpha/umami, written once: ~/project is emptied on
# every deploy, while the Postgres volume keeps the password it was created
# with and a new APP_SECRET would invalidate every session and share token.
STORE="${HOME}/.panelalpha/umami"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    (
        umask 077
        POSTGRES_PASSWORD=$(openssl rand -hex 16)
        {
            printf 'APP_SECRET=%s\n' "$(openssl rand -hex 32)"
            printf 'POSTGRES_PASSWORD=%s\n' "${POSTGRES_PASSWORD}"
            printf 'DATABASE_URL=postgresql://umami:%s@db:5432/umami\n' "${POSTGRES_PASSWORD}"
        } > "${STORE}/secrets.env"
    )
fi
chmod 600 "${STORE}/secrets.env"

# The upstream compose sets these under environment:, which beats env_file, so
# the override interpolates them from .env; rewrite it from the store every deploy.
install -m 600 "${STORE}/secrets.env" .env
