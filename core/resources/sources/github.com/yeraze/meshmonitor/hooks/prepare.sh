#!/bin/bash
# Generates the session secret once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The admin login is the engine's (`credentials:`), in
# ~/.panelalpha/app-credentials.env, read only by the seed service.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/meshmonitor"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'SESSION_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"

chmod +rx panelalpha-seed.sh
