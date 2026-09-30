#!/bin/bash
# Generates the Rails/encryption secrets, the PostgreSQL password and the single
# account's ID once into ~/.panelalpha/keygen; the database keeps the first values.
set -e
DIR="${HOME}/.panelalpha/keygen"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_USER=keygen\nPOSTGRES_DB=keygen\nPOSTGRES_PASSWORD=%s\n' "${pw}" > "${DIR}/db.env"
      printf '%s\n' \
        "DATABASE_URL=postgres://keygen:${pw}@postgres:5432/keygen" \
        "SECRET_KEY_BASE=$(openssl rand -hex 64)" \
        "ENCRYPTION_DETERMINISTIC_KEY=$(openssl rand -base64 32)" \
        "ENCRYPTION_PRIMARY_KEY=$(openssl rand -base64 32)" \
        "ENCRYPTION_KEY_DERIVATION_SALT=$(openssl rand -base64 32)" \
        "KEYGEN_ACCOUNT_ID=$(cat /proc/sys/kernel/random/uuid)" > "${DIR}/app.env" )
    echo "[keygen] generated secrets, database password and account ID -> ${DIR}" >&2
fi
chmod 600 "${DIR}"/*.env
