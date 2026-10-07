#!/bin/bash
# Generate secrets once, where a redeploy will not wipe them (~/project is
# emptied on every deploy), and seed .env defaults. The admin login
# is the engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[linkwarden] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/linkwarden"
DB_ENV="${STORE_DIR}/db.env"
MEILI_ENV="${STORE_DIR}/meili.env"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ] || [ ! -f "${MEILI_ENV}" ]; then
    PG_PASSWORD="$(rnd 24)"
    MEILI_KEY="$(rnd 32)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
        printf 'MEILI_MASTER_KEY=%s\n' "${MEILI_KEY}" > "${MEILI_ENV}"
        cat > "${APP_ENV}" <<ENV_EOF
# Written once; the database volume holds this password and sessions are
# signed with NEXTAUTH_SECRET, so do not regenerate.
NEXTAUTH_SECRET=$(rnd 48)
DATABASE_URL=postgresql://linkwarden:${PG_PASSWORD}@postgres:5432/linkwarden
MEILI_HOST=http://meilisearch:7700
MEILI_MASTER_KEY=${MEILI_KEY}
ENV_EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# Defaults the panel's env vars can override; nothing secret here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
write_default NEXT_PUBLIC_DISABLE_REGISTRATION true
write_default NEXT_PUBLIC_CREDENTIALS_ENABLED true
