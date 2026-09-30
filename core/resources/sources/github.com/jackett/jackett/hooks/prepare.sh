#!/bin/bash
# Generates the admin password and API key once, in ~/.panelalpha (survives
# redeploys; ~/project does not). The init service seeds them into Jackett.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/jackett"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/jackett.env" ]; then
    pw="$(openssl rand -base64 24 | tr -d '\n=/+')"
    key="$(openssl rand -hex 16)"
    (umask 077; printf 'JACKETT_ADMIN_PASSWORD=%s\nJACKETT_API_KEY=%s\n' "$pw" "$key" > "${STORE}/jackett.env")
fi
chmod 600 "${STORE}/jackett.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/jackett-seed.sh
