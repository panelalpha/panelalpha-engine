#!/bin/bash
# Database password and session secret, generated once into ~/.panelalpha
# (a redeploy empties ~/project; a new secret would log everyone out).
set -e
STORE="${HOME}/.panelalpha/saltcorn"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; printf 'POSTGRES_USER=saltcorn\nPOSTGRES_DB=saltcorn\nPOSTGRES_PASSWORD=%s\nPGUSER=saltcorn\nPGDATABASE=saltcorn\nPGPASSWORD=%s\n' "$pw" "$pw" > "${STORE}/db.env")
fi
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; printf 'SALTCORN_SESSION_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
