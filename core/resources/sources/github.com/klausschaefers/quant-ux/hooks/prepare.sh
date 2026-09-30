#!/bin/bash
# Generates the backend's JWT secret once in ~/.panelalpha (survives
# redeploys; ~/project does not), so logins stay valid across a rebuild.
set -e
STORE="${HOME}/.panelalpha/quantux"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/backend.env" ]; then
    (umask 077; printf 'QUX_JWT_PASSWORD=%s\n' "$(openssl rand -hex 32)" > "${STORE}/backend.env")
fi
chmod 600 "${STORE}/backend.env"
