#!/bin/bash
# Generate the PostgreSQL password once into ~/.panelalpha/langflow (survives redeploys; ~/project does not).
set -e
STORE_DIR="${HOME}/.panelalpha/langflow"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077; printf 'POSTGRES_PASSWORD=%s\nLANGFLOW_DATABASE_URL=postgresql://langflow:%s@postgres:5432/langflow\n' "$pw" "$pw" > "${STORE_DIR}/db.env" )
    echo "[langflow] database password written to ${STORE_DIR}" >&2
fi
