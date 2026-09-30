#!/bin/bash
# Generates Lago's secrets once in ~/.panelalpha/lago (survives redeploys;
# ~/project does not). The RSA key signs sessions and the encryption keys
# protect stored credentials, so they must never change after the first run.
set -e
STORE="${HOME}/.panelalpha/lago"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077
if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    PW=$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")
    {
        printf 'DATABASE_URL=postgresql://lago:%s@db:5432/lago?search_path=public\n' "${PW}"
        printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -hex 64)"
        printf 'LAGO_RSA_PRIVATE_KEY=%s\n' "$(openssl genrsa 2048 2>/dev/null | openssl base64 -A)"
        printf 'LAGO_ENCRYPTION_PRIMARY_KEY=%s\n' "$(openssl rand -hex 16)"
        printf 'LAGO_ENCRYPTION_DETERMINISTIC_KEY=%s\n' "$(openssl rand -hex 16)"
        printf 'LAGO_ENCRYPTION_KEY_DERIVATION_SALT=%s\n' "$(openssl rand -hex 16)"
    } > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
