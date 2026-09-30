#!/bin/bash
# Generates Phoenix's SECRET_KEY_BASE and the Guardian JWT key once, in
# ~/.panelalpha (survives redeploys; ~/project does not). Mydia will not boot without them.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/mydia"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secrets.env" ]; then
    (umask 077; printf 'SECRET_KEY_BASE=%s\nGUARDIAN_SECRET_KEY=%s\n' \
        "$(openssl rand -hex 48)" "$(openssl rand -hex 48)" > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"

# The compose file lists .env; make sure it exists.
touch .env
