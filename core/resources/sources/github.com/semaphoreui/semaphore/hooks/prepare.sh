#!/bin/bash
# Generates Semaphore's three keys once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The admin login is the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this hook.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/semaphore"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secrets.env" ]; then
    (umask 077; {
        # AES keys: must decode to 16/24/32 bytes, and must never change once
        # access keys have been encrypted with them.
        printf 'SEMAPHORE_ACCESS_KEY_ENCRYPTION=%s\n' "$(openssl rand -base64 32)"
        printf 'SEMAPHORE_COOKIE_HASH=%s\n' "$(openssl rand -base64 32)"
        printf 'SEMAPHORE_COOKIE_ENCRYPTION=%s\n' "$(openssl rand -base64 32)"
    } > "${STORE}/secrets.env")
fi
# An older deploy kept the login here too; the engine adopted it.
sed -i '/^SEMAPHORE_ADMIN\(_PASSWORD\)\?=/d' "${STORE}/secrets.env"
chmod 600 "${STORE}/secrets.env"

# The compose file lists .env; make sure it exists.
touch .env
