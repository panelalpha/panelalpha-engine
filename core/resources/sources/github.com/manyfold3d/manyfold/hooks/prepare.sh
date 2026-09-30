#!/bin/bash
# Generate SECRET_KEY_BASE once into ~/.panelalpha (~/project is re-cloned on
# every deploy; sessions and encrypted settings need the same key).
set -e
DATA="${HOME}/.panelalpha/manyfold"
ENV_FILE="${DATA}/app.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -hex 64)" > "${ENV_FILE}"
    echo "[panelalpha] manyfold: generated SECRET_KEY_BASE in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
