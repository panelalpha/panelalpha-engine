#!/bin/bash
# Generates the API key once, in ~/.panelalpha (survives redeploys; ~/project
# does not). The login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/mylar"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    # Mylar rejects an API key that is not exactly 32 characters.
    key="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${STORE}/app.env" <<EOF
PUID=$(id -u)
PGID=$(id -g)
MYLAR_API_KEY=${key}
EOF
    )
fi
chmod 600 "${STORE}"/*

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/entrypoint.sh
