#!/bin/bash
# Generates the login password and JWT key once, in ~/.panelalpha (survives
# redeploys; ~/project does not). flatnotes refuses to start without them.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/flatnotes"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/auth.env" ]; then
    (umask 077; printf 'FLATNOTES_USERNAME=admin\nFLATNOTES_PASSWORD=%s\nFLATNOTES_SECRET_KEY=%s\n' \
        "$(openssl rand -hex 16)" "$(openssl rand -hex 32)" > "${STORE}/auth.env")
fi
chmod 600 "${STORE}/auth.env"

# The compose file lists .env; make sure it exists.
touch .env
