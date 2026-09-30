#!/bin/bash
# Writes the Superuser's super.json and generates the MariaDB password once, in
# ~/.panelalpha (survives redeploys; ~/project does not). The Superuser login is
# the engine's (`credentials:` in panelalpha.yaml): `email` / `password` in
# ~/.panelalpha/app-credentials.env, the names the old credentials.txt used.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/shinobi"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# super.json holds sha256(password), the hash conf.sample.json's passwordType
# selects. Written once: a password changed in /super is saved into this file.
if [ ! -s "${STORE}/super.json" ]; then
    set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
    hash="$(printf '%s' "$password" | openssl dgst -sha256 -r | cut -d' ' -f1)"
    (umask 077; printf '[\n    {\n        "mail": "%s",\n        "pass": "%s"\n    }\n]\n' "$email" "$hash" > "${STORE}/super.json")
    echo "[panelalpha] shinobi: wrote ${STORE}/super.json"
fi
chmod 600 "${STORE}/super.json"

if [ ! -s "${STORE}/db.env" ]; then
    (umask 077; printf 'DB_PASSWORD=%s\n' "$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 32)" > "${STORE}/db.env")
    echo "[panelalpha] shinobi: generated the database password into ${STORE}/db.env"
fi
chmod 600 "${STORE}/db.env"
