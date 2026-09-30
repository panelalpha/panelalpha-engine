#!/bin/bash
# Generates the Rails secret_key_base once, in ~/.panelalpha (survives
# redeploys; ~/project does not), so sessions survive a rebuild.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/redmine"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secret.env" ]; then
    (umask 077; printf 'REDMINE_SECRET_KEY_BASE=%s\n' "$(openssl rand -hex 64)" > "${STORE}/secret.env")
fi
chmod 600 "${STORE}/secret.env"
