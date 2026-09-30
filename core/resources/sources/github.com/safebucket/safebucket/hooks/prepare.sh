#!/bin/bash
# Token/MFA secrets and RustFS keys generated once into ~/.panelalpha:
# ~/project is wiped on every deploy, and new keys would orphan the stored
# objects and MFA secrets.
set -e
STORE="${HOME}/.panelalpha/safebucket"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    AK=$(openssl rand -hex 12)
    SK=$(openssl rand -hex 24)
    (umask 077
     printf 'RUSTFS_ACCESS_KEY=%s\nRUSTFS_SECRET_KEY=%s\nAWS_ACCESS_KEY_ID=%s\nAWS_SECRET_ACCESS_KEY=%s\n' \
       "${AK}" "${SK}" "${AK}" "${SK}" > "${STORE}/s3.env"
     # MFA key must be exactly 32 characters.
     printf 'APP__TOKEN_SECRET=%s\nAPP__MFA_ENCRYPTION_KEY=%s\nSTORAGE__RUSTFS__ACCESS_KEY=%s\nSTORAGE__RUSTFS__SECRET_KEY=%s\n' \
       "$(openssl rand -hex 32)" "$(openssl rand -hex 16)" "${AK}" "${SK}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env" "${STORE}/s3.env"
