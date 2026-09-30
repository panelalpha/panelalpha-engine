#!/bin/bash
# Generates the first user's credentials once, in ~/.panelalpha (survives
# redeploys; ~/project does not). Beszel's initial migration reads them.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/beszel"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'USER_EMAIL=admin@example.com\nUSER_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

# The compose file lists .env; make sure it exists.
touch .env
