#!/bin/bash
# SECRET_KEY (encrypts stored user configs) and the database password, generated
# once into ~/.panelalpha: ~/project is wiped on every deploy.
set -e
STORE="${HOME}/.panelalpha/mediafusion"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    db="$(openssl rand -hex 24)"
    (umask 077; cat > "${STORE}/app.env" <<ENV
SECRET_KEY=$(openssl rand -hex 16)
POSTGRES_PASSWORD=${db}
POSTGRES_URI=postgresql://mediafusion:${db}@postgres:5432/mediafusion
ENV
    )
fi
chmod 600 "${STORE}/app.env"
