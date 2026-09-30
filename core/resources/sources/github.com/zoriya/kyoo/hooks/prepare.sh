#!/bin/bash
# Generates this account's database password and scanner API key ONCE into
# ~/.panelalpha/kyoo/ (survives redeploys; ~/project does not). Upstream's
# .env.example ships fixed values for both.
set -e
STORE="${HOME}/.panelalpha/kyoo"
SECRETS="${STORE}/secrets.env"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${SECRETS}" ]; then
    PGPW="$(openssl rand -hex 24)"
    APIKEY="$(openssl rand -hex 32)"
    (
        umask 077
        printf 'PGPASSWORD=%s\nPOSTGRES_PASSWORD=%s\nKEIBI_APIKEY_SCANNER=%s\nKYOO_APIKEY=%s\n' \
            "${PGPW}" "${PGPW}" "${APIKEY}" "${APIKEY}" > "${SECRETS}"
    )
    echo "[kyoo] secrets written to ${STORE}" >&2
fi
chmod 600 "${SECRETS}"
# The project's .env is optional input (customer settings); make sure it exists.
touch "${HOME}/project/.env"
