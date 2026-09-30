#!/bin/bash
# Database password and Phoenix/Cloak secrets, generated once into ~/.panelalpha
# (a redeploy empties ~/project; a new vault key would orphan encrypted configs).
set -e
STORE="${HOME}/.panelalpha/accent"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/db.env" ]; then
    (umask 077; printf 'POSTGRES_USER=accent\nPOSTGRES_DB=accent\nPOSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${STORE}/db.env")
fi
if [ ! -s "${STORE}/app.env" ]; then
    pw="$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")"
    (umask 077; {
        printf 'DATABASE_URL=postgres://accent:%s@db:5432/accent\n' "$pw"
        printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -base64 48 | tr -d '\n')"
        printf 'SIGNING_SALT=%s\n' "$(openssl rand -hex 16)"
        printf 'MACHINE_TRANSLATIONS_VAULT_KEY=%s\n' "$(openssl rand -base64 32)"
    } > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
