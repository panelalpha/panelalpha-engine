#!/bin/bash
# Generates the login password and API key once, in ~/.panelalpha (survives
# redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/mylar"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    pass="$(openssl rand -hex 16)"
    # Mylar rejects an API key that is not exactly 32 characters.
    key="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${STORE}/app.env" <<EOF
PUID=$(id -u)
PGID=$(id -g)
MYLAR_USER=admin
MYLAR_PASSWORD=${pass}
MYLAR_API_KEY=${key}
EOF
        cat > "${STORE}/credentials.txt" <<EOF
Mylar3 login: admin / ${pass}
API key:      ${key}
Seeded into config.ini on the first boot only; change them in Settings afterwards.
EOF
    )
fi
chmod 600 "${STORE}"/*

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/entrypoint.sh
