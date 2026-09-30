#!/bin/bash
# Generates SSO_SECRET (session signing + SSO credential encryption) once into
# ~/.panelalpha; ~/project is re-cloned on every deploy.
set -e
DATA="${HOME}/.panelalpha/windshift"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    echo "SSO_SECRET=$(openssl rand -hex 32)" > "${ENV_FILE}"
    echo "[panelalpha] windshift: generated SSO_SECRET in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
