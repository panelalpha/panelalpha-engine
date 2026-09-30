#!/bin/bash
# Account shell, cwd ~/project, before `compose up`. Generates the secrets once
# into ~/.panelalpha/notifuse (survives redeploys; ~/project is re-cloned).
set -e
DATA="${HOME}/.panelalpha/notifuse"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PGPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
POSTGRES_PASSWORD=${PGPW}
DB_PASSWORD=${PGPW}
# Signs sessions and encrypts stored provider credentials: must never change.
SECRET_KEY=$(openssl rand -base64 64 | tr -d '\n')
ENVEOF
    echo "[panelalpha] notifuse: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
