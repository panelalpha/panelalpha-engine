#!/bin/bash
# Generates the JWT key once, in ~/.panelalpha (survives redeploys; ~/project
# does not). The login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/flatnotes"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/auth.env" ]; then
    (umask 077; printf 'FLATNOTES_SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/auth.env")
fi
# An older deploy kept the login here too; the engine adopted it.
sed -i '/^FLATNOTES_\(USERNAME\|PASSWORD\)=/d' "${STORE}/auth.env"
chmod 600 "${STORE}/auth.env"

# The compose file lists .env; make sure it exists.
touch .env
