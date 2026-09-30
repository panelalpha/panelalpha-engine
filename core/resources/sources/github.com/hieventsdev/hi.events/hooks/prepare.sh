#!/bin/bash
# Generate APP_KEY, JWT_SECRET and the DB password once into ~/.panelalpha
# (~/project is re-cloned on every deploy; the database keeps the first ones).
set -e
DATA="${HOME}/.panelalpha/hievents"
ENV_FILE="${DATA}/app.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PW=$(openssl rand -hex 24)
    {
        printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
        printf 'JWT_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'POSTGRES_PASSWORD=%s\n' "${PW}"
        printf 'DATABASE_URL=postgresql://postgres:%s@postgres:5432/hi-events\n' "${PW}"
    } > "${ENV_FILE}"
    echo "[panelalpha] hievents: generated APP_KEY, JWT_SECRET and the DB password in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
