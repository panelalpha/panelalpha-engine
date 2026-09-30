#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The init service hashes it into config.yaml only when
# the data volume has no config yet, so it is the first-run password.
set -e

STORE="${HOME}/.panelalpha/wikigo"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/admin.env" ]; then
    printf 'WIKIGO_ADMIN_USER=admin\nWIKIGO_ADMIN_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" > "${STORE}/admin.env"
fi
chmod 600 "${STORE}/admin.env"
