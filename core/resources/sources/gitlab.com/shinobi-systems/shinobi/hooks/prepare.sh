#!/bin/bash
# Generates the Superuser password and the MariaDB password once, in
# ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/shinobi"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/credentials.txt" ]; then
    pw="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    (umask 077; printf 'superuser_url=/super\nemail=admin@shinobi.video\npassword=%s\n' "$pw" > "${STORE}/credentials.txt")
    echo "[panelalpha] shinobi: generated the Superuser password into ${STORE}/credentials.txt"
fi
chmod 600 "${STORE}/credentials.txt"

# super.json holds sha256(password), the hash conf.sample.json's passwordType
# selects. Written once: a password changed in /super is saved into this file.
if [ ! -s "${STORE}/super.json" ]; then
    mail="$(sed -n 's/^email=//p' "${STORE}/credentials.txt")"
    pw="$(sed -n 's/^password=//p' "${STORE}/credentials.txt")"
    hash="$(printf '%s' "$pw" | openssl dgst -sha256 -r | cut -d' ' -f1)"
    (umask 077; printf '[\n    {\n        "mail": "%s",\n        "pass": "%s"\n    }\n]\n' "$mail" "$hash" > "${STORE}/super.json")
    echo "[panelalpha] shinobi: wrote ${STORE}/super.json"
fi
chmod 600 "${STORE}/super.json"

if [ ! -s "${STORE}/db.env" ]; then
    (umask 077; printf 'DB_PASSWORD=%s\n' "$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 32)" > "${STORE}/db.env")
    echo "[panelalpha] shinobi: generated the database password into ${STORE}/db.env"
fi
chmod 600 "${STORE}/db.env"
