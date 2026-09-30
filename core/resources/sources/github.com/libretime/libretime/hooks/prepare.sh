#!/bin/bash
# Generate the service passwords and LibreTime's API/secret keys once; they
# live outside ~/project, which every deploy wipes.
set -e
STORE="${HOME}/.panelalpha/libretime"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/secrets.env" ]; then
    (
        umask 077
        cat > "${STORE}/secrets.env" <<EOT
POSTGRES_PASSWORD=$(openssl rand -hex 16)
RABBITMQ_DEFAULT_PASS=$(openssl rand -hex 16)
ICECAST_SOURCE_PASSWORD=$(openssl rand -hex 16)
ICECAST_ADMIN_PASSWORD=$(openssl rand -hex 16)
ICECAST_RELAY_PASSWORD=$(openssl rand -hex 16)
LT_API_KEY=$(openssl rand -hex 32)
LT_SECRET_KEY=$(openssl rand -hex 32)
EOT
    )
    echo "[libretime] generated secrets in ${STORE}" >&2
else
    echo "[libretime] reusing secrets in ${STORE}" >&2
fi
