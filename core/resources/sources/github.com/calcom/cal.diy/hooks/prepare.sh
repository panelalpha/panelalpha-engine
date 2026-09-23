#!/bin/bash
set -e

# Generate secure credentials
POSTGRES_PASSWORD=$(openssl rand -hex 16)
NEXTAUTH_SECRET=$(openssl rand -base64 32)
CALENDSO_ENCRYPTION_KEY=$(openssl rand -base64 24)

# Set up .env from the example bundled in the repo
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
