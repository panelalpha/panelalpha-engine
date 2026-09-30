#!/bin/bash
# Generate the database password and app secrets once into ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/bookorbit"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'POSTGRES_PASSWORD=%s\nJWT_SECRET=%s\nPODCAST_ENCRYPTION_KEY=%s\nSETUP_BOOTSTRAP_TOKEN=%s\n' \
        "$(openssl rand -hex 24)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 16)" \
        > "${STORE}/app.env")
    echo "[panelalpha] bookorbit: generated secrets into ${STORE}/app.env" >&2
fi
chmod 600 "${STORE}/app.env"

# Postgres gets only its password.
if [ ! -f "${STORE}/db.env" ]; then
    (umask 077; grep '^POSTGRES_PASSWORD=' "${STORE}/app.env" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/db.env"

touch .env
