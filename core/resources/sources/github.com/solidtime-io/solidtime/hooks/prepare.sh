#!/bin/bash
# Generates APP_KEY, the Passport RSA pair and the Postgres password once, in
# ~/.panelalpha (survives redeploys; ~/project does not). The owner's login is
# the engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
# Regenerating any of them against the surviving volumes would lock solidtime
# out of its data or invalidate every issued API token.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/solidtime"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/db.env" ]; then
    printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    key=$(mktemp)
    openssl genrsa -out "${key}" 4096 2>/dev/null
    # Single-line PEM with literal \n, the format upstream's laravel.env uses.
    priv=$(awk '{printf "%s\\n", $0}' "${key}")
    pub=$(openssl rsa -in "${key}" -pubout 2>/dev/null | awk '{printf "%s\\n", $0}')
    rm -f "${key}"
    {
        printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
        printf 'DB_PASSWORD=%s\n' "$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")"
        printf 'PASSPORT_PRIVATE_KEY="%s"\n' "${priv}"
        printf 'PASSPORT_PUBLIC_KEY="%s"\n' "${pub}"
    } > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
