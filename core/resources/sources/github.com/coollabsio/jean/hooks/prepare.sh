#!/bin/bash
# Generates Jean's access token once in ~/.panelalpha (survives redeploys;
# ~/project does not). The customer reads it there to log in.
set -e
STORE="${HOME}/.panelalpha/jean"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/token.env" ]; then
    (umask 077; printf 'JEAN_TOKEN=%s\n' "$(openssl rand -hex 32)" > "${STORE}/token.env")
fi
chmod 600 "${STORE}/token.env"
