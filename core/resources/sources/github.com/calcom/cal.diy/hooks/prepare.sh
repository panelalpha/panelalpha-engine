#!/bin/bash
set -e

# Secrets are generated once into ~/.panelalpha/caldiy: ~/project (and its .env)
# is emptied on every deploy, while the Postgres volume keeps the password it was
# created with and CALENDSO_ENCRYPTION_KEY decrypts the stored app credentials.
STORE="${HOME}/.panelalpha/caldiy"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    (
        umask 077
        {
            printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)"
            printf 'NEXTAUTH_SECRET=%s\n' "$(openssl rand -base64 32)"
            printf 'CALENDSO_ENCRYPTION_KEY=%s\n' "$(openssl rand -base64 24)"
        } > "${STORE}/secrets.env.tmp"
        mv "${STORE}/secrets.env.tmp" "${STORE}/secrets.env"
    )
fi
chmod 600 "${STORE}/secrets.env"
POSTGRES_PASSWORD=$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/secrets.env")
NEXTAUTH_SECRET=$(sed -n 's/^NEXTAUTH_SECRET=//p' "${STORE}/secrets.env")
CALENDSO_ENCRYPTION_KEY=$(sed -n 's/^CALENDSO_ENCRYPTION_KEY=//p' "${STORE}/secrets.env")

# Set up .env from the example bundled in the repo, on every deploy: the compose
# file reads it as env_file and interpolates POSTGRES_PASSWORD from it
cp .env.example .env

# Patch keys that exist in .env.example in-place (append would be shadowed by the earlier empty value)
sed -i "s|^DATABASE_URL=.*|DATABASE_URL=postgresql://unicorn_user:${POSTGRES_PASSWORD}@database/calendso|" .env
sed -i "s|^DATABASE_DIRECT_URL=.*|DATABASE_DIRECT_URL=postgresql://unicorn_user:${POSTGRES_PASSWORD}@database/calendso|" .env
sed -i "s|^NEXTAUTH_SECRET=.*|NEXTAUTH_SECRET=${NEXTAUTH_SECRET}|" .env
sed -i "s|^CALENDSO_ENCRYPTION_KEY=.*|CALENDSO_ENCRYPTION_KEY=${CALENDSO_ENCRYPTION_KEY}|" .env

# Patch or append REDIS_URL (needed by the app; default in .env.example may be empty)
sed -i "s|^REDIS_URL=.*|REDIS_URL=redis://redis:6379|" .env
grep -q "^REDIS_URL=" .env || printf '\nREDIS_URL=redis://redis:6379\n' >> .env

# POSTGRES_PASSWORD is not in .env.example — append it on its own line
printf '\nPOSTGRES_PASSWORD=%s\n' "${POSTGRES_PASSWORD}" >> .env

# Pre-pull the image so that `docker compose up -d` (run by the engine after this script) starts instantly
docker pull calcom/cal.com:latest
