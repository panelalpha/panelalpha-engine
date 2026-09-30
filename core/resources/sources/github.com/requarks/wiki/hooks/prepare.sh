#!/bin/bash
set -e

# The database password the compose file cannot supply for itself, written once
# into ~/.panelalpha/wiki and read through env_file. Not into ~/project: it is
# emptied on every deploy, while the PostgreSQL volume outlives it, so a
# regenerated POSTGRES_PASSWORD would lock Wiki.js out of its own database.
# The admin login (WIKI_ADMIN_EMAIL / WIKI_ADMIN_PASSWORD) is the engine's
# (`credentials:`), in ~/.panelalpha/app-credentials.env.
#
# Nothing else needs a secret here: Wiki.js generates its own sessionSecret and
# its RSA keypair during /finalize and keeps them in the `settings` table, on
# the same volume as everything else.
STORE="${HOME}/.panelalpha/wiki"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
(
    umask 077
    if [ ! -s "${STORE}/db.env" ]; then
        printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
    fi
    if [ ! -s "${STORE}/wiki.env" ]; then
        printf 'DB_PASS=%s\n' "$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/wiki.env"
    fi
)
chmod 600 "${STORE}/db.env" "${STORE}/wiki.env"
