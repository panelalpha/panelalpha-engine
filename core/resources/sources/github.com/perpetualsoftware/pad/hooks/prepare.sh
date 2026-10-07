#!/bin/bash
# Pad needs a 64-hex-char PAD_ENCRYPTION_KEY; the engine's generated secrets
# are 48. Written once outside ~/project, which every deploy wipes.
set -e
STORE_DIR="${HOME}/.panelalpha/pad"
KEY_ENV="${STORE_DIR}/encryption.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${KEY_ENV}" ]; then
    (umask 077; printf 'PAD_ENCRYPTION_KEY=%s\n' "$(openssl rand -hex 32)" > "${KEY_ENV}")
    echo "[pad] encryption key written to ${KEY_ENV}" >&2
else
    echo "[pad] reusing the encryption key in ${KEY_ENV}" >&2
fi
