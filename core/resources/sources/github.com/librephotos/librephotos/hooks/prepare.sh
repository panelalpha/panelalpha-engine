#!/bin/bash
# Generate the database password once into ~/.panelalpha (~/project is
# re-cloned on every deploy; the database volume keeps the first one).
set -e
DATA="${HOME}/.panelalpha/librephotos"
ENV_FILE="${DATA}/db.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    PW=$(openssl rand -hex 24)
    printf 'POSTGRES_PASSWORD=%s\nDB_PASS=%s\n' "${PW}" "${PW}" > "${ENV_FILE}"
    echo "[panelalpha] librephotos: generated the database password in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
