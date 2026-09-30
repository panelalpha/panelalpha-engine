#!/bin/bash
# Database credentials and the hashing secret, generated once into ~/.panelalpha
# (a redeploy empties ~/project; a new secret would invalidate every password).
set -e
STORE="${HOME}/.panelalpha/szurubooru"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/db.env" ]; then
    (umask 077; printf 'POSTGRES_USER=szuru\nPOSTGRES_DB=szuru\nPOSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${STORE}/db.env")
fi
chmod 600 "${STORE}/db.env"

if [ ! -s "${STORE}/config.yaml" ]; then
    printf '# Merged over config.yaml.dist; only the placeholder secret is replaced.\nsecret: %s\n' "$(openssl rand -hex 32)" > "${STORE}/config.yaml"
fi
# Read by the server's non-root user; the 0700 directory keeps it private.
chmod 644 "${STORE}/config.yaml"
