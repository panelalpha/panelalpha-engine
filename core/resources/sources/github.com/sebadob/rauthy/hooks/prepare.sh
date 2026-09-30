#!/bin/bash
# Generates Rauthy's boot secrets once, in ~/.panelalpha (survives redeploys;
# ~/project does not): the Hiqlite Raft/API secrets and the data encryption key.
# Losing the key makes the encrypted data in the volume unreadable.
set -e

STORE="${HOME}/.panelalpha/rauthy"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/secrets.env" ]; then
    kid="pa$(openssl rand -hex 4)"
    (umask 077; {
        printf 'HQL_SECRET_RAFT=%s\n' "$(openssl rand -hex 32)"
        printf 'HQL_SECRET_API=%s\n' "$(openssl rand -hex 32)"
        printf 'ENC_KEYS=%s/%s\n' "$kid" "$(openssl rand -base64 32)"
        printf 'ENC_KEY_ACTIVE=%s\n' "$kid"
    } > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"
