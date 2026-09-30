#!/bin/bash
# Generates Coral's SIGNING_SECRET once in ~/.panelalpha (survives redeploys;
# ~/project does not). A new secret would invalidate every session.
set -e
STORE="${HOME}/.panelalpha/coral"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/app.env" ]; then
    printf 'SIGNING_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env"
fi
chmod 600 "${STORE}/app.env"
