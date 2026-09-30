#!/bin/bash
# Generate the database password and the session signing key once, outside
# ~/project (wiped every deploy); the password is baked into the pgdata volume.
set -e
STORE_DIR="${HOME}/.panelalpha/argilla"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/db.env" ]; then
    PW="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "$PW" > "${STORE_DIR}/db.env"
        {
            printf 'ARGILLA_DATABASE_URL=postgresql+asyncpg://postgres:%s@postgres:5432/argilla\n' "$PW"
            printf 'ARGILLA_AUTH_SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
        } > "${STORE_DIR}/app.env"
    )
    echo "[argilla] secrets written to ${STORE_DIR}" >&2
fi
