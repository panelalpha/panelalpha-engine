#!/bin/bash
# Generates the admin password and Semaphore's three keys once, in
# ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/semaphore"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secrets.env" ]; then
    (umask 077; {
        printf 'SEMAPHORE_ADMIN=admin\n'
        printf 'SEMAPHORE_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)"
        # AES keys: must decode to 16/24/32 bytes, and must never change once
        # access keys have been encrypted with them.
        printf 'SEMAPHORE_ACCESS_KEY_ENCRYPTION=%s\n' "$(openssl rand -base64 32)"
        printf 'SEMAPHORE_COOKIE_HASH=%s\n' "$(openssl rand -base64 32)"
        printf 'SEMAPHORE_COOKIE_ENCRYPTION=%s\n' "$(openssl rand -base64 32)"
    } > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"

# The compose file lists .env; make sure it exists.
touch .env
