#!/bin/bash
# Database password, generated once in ~/.panelalpha: ~/project is wiped on every
# deploy and the MariaDB volume keeps the first password it was given.
set -e
STORE="${HOME}/.panelalpha/passbolt"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    (umask 077; printf 'DATASOURCES_DEFAULT_PASSWORD=%s\nMARIADB_PASSWORD=%s\n' "$pw" "$pw" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/db.env"
