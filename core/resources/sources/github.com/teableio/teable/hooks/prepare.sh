#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password, and SECRET_KEY signs sessions.
set -e
STORE="${HOME}/.panelalpha/teable"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/teable.env" ]; then
    db="$(openssl rand -hex 24)"
    redis="$(openssl rand -hex 24)"
    (umask 077; printf '%s\n' \
        "SECRET_KEY=$(openssl rand -base64 32)" \
        "POSTGRES_PASSWORD=${db}" \
        "REDIS_PASSWORD=${redis}" \
        "PRISMA_DATABASE_URL=postgresql://teable:${db}@teable-db:5432/teable" \
        "BACKEND_CACHE_REDIS_URI=redis://default:${redis}@teable-cache:6379/0" \
        > "${STORE}/teable.env")
fi
chmod 600 "${STORE}/teable.env"
