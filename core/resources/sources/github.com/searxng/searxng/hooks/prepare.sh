#!/bin/bash
# Generate server.secret_key once into ~/.panelalpha (survives redeploys;
# ~/project does not). Without it SearXNG runs on "ultrasecretkey".
set -e
cd ~/project

STORE="${HOME}/.panelalpha/searxng"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secret.env" ]; then
    (umask 077; printf 'SEARXNG_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/secret.env")
fi
chmod 600 "${STORE}/secret.env"

# The compose file lists .env; make sure it exists.
touch .env
