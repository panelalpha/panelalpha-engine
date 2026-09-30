#!/bin/bash
# ZITADEL needs a 32-character masterkey, the login UI a cookie secret and the
# database a password. Generated once into ~/.panelalpha (~/project is wiped on
# every deploy; a new masterkey could not decrypt the existing data).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/zitadel"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    db_pass="$(openssl rand -hex 24)"
    (umask 077
     printf 'POSTGRES_USER=postgres\nPOSTGRES_DB=zitadel\nPOSTGRES_PASSWORD=%s\n' "${db_pass}" > "${STORE}/db.env"
     printf 'ZITADEL_MASTERKEY=%s\nZITADEL_DATABASE_POSTGRES_DSN=postgresql://postgres:%s@postgres:5432/zitadel?sslmode=disable\n' \
        "$(openssl rand -hex 16)" "${db_pass}" > "${STORE}/api.env"
     printf 'ZITADEL_SESSION_COOKIE_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/login.env")
fi
chmod 600 "${STORE}"/*.env
