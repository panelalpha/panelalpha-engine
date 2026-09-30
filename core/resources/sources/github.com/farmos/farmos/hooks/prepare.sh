#!/bin/bash
# Generates the PostgreSQL password once in ~/.panelalpha (survives redeploys;
# ~/project does not). The farmOS installer asks for it on its database step.
set -e
STORE="${HOME}/.panelalpha/farmos"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
chmod 600 "${STORE}/db.env"
