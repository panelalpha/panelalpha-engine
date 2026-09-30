#!/bin/bash
# Generates the UI session-cookie secret once, in ~/.panelalpha (survives
# redeploys; ~/project does not), so a restart does not log everyone out.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/nzbdav"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'SESSION_KEY=%s\n' "$(openssl rand -hex 64)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"

# The compose file lists .env; make sure it exists.
touch .env
