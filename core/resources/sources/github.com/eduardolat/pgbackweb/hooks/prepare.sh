#!/bin/bash
# Generates the metadata-DB password and the encryption key ONCE into
# ~/.panelalpha/pgbackweb (survives redeploys; ~/project does not). The admin
# login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
# Regenerating the encryption key would make every stored connection string
# unreadable, so these files are never rewritten.
set -e

say() { echo "[pgbackweb] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/pgbackweb"
SECRETS="${STORE_DIR}/secrets.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${SECRETS}" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    ( umask 077
      cat > "${SECRETS}" <<EOT
# Written once on the first deploy. Do not change: the metadata database was
# initialised with this password and stored credentials are encrypted with the key.
POSTGRES_PASSWORD=${DB_PASSWORD}
PBW_POSTGRES_CONN_STRING=postgresql://pgbackweb:${DB_PASSWORD}@postgres:5432/pgbackweb?sslmode=disable
PBW_ENCRYPTION_KEY=$(openssl rand -hex 32)
EOT
    )
    say "generated database password and encryption key -> ${SECRETS}"
fi
chmod 600 "${SECRETS}"

docker pull eduardolat/pgbackweb:0.5.2 >/dev/null 2>&1 || true
docker pull postgres:17-alpine >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
say "prepare complete"
