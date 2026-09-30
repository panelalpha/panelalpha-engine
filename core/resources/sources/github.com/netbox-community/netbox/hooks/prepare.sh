#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# and the Postgres volume keeps the first password it was given.
set -e
STORE="${HOME}/.panelalpha/netbox"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/netbox.env" ]; then
    db="$(openssl rand -hex 24)"
    rd="$(openssl rand -hex 24)"
    # SECRET_KEY and the token pepper must be at least 50 characters.
    (umask 077; printf 'SECRET_KEY=%s\nAPI_TOKEN_PEPPER_1=%s\nDB_PASSWORD=%s\nPOSTGRES_PASSWORD=%s\nREDIS_PASSWORD=%s\nREDIS_CACHE_PASSWORD=%s\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$db" "$db" "$rd" "$rd" > "${STORE}/netbox.env")
fi
chmod 600 "${STORE}/netbox.env"
