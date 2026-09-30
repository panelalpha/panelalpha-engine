#!/bin/bash
set -e

# Three values the compose file cannot supply for itself, written once into
# ~/.panelalpha/wiki and read through env_file. Not into ~/project: it is
# emptied on every deploy, while the PostgreSQL volume outlives it, so a
# regenerated POSTGRES_PASSWORD would lock Wiki.js out of its own database,
# and a regenerated admin password would be one the `users` table never
# learns -- files/panelalpha-setup.sh only runs the wizard while the wiki is
# still unconfigured.
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
        {
            printf 'DB_PASS=%s\n' "$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")"
            printf 'WIKI_ADMIN_EMAIL=admin@example.com\n'
            printf 'WIKI_ADMIN_PASSWORD=%s\n' "$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"
        } > "${STORE}/wiki.env"
    fi
)
chmod 600 "${STORE}/db.env" "${STORE}/wiki.env"
