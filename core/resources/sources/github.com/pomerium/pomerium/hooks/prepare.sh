#!/bin/bash
# Generates Pomerium's shared and cookie secrets once, in ~/.panelalpha
# (survives redeploys; ~/project does not). New secrets would sign everyone out.
set -e

STORE="${HOME}/.panelalpha/pomerium"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secrets.env" ]; then
    (umask 077
     printf 'SHARED_SECRET=%s\nCOOKIE_SECRET=%s\n' "$(openssl rand -base64 32)" "$(openssl rand -base64 32)" > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"
