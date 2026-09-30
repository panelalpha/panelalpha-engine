#!/bin/bash
# Generates the admin password and session secret once, in ~/.panelalpha
# (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/meshmonitor"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service, which sets it through the app's own API.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'MESHMONITOR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'SESSION_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/admin.env" "${STORE}/app.env"

chmod +rx panelalpha-seed.sh
