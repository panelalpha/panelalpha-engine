#!/bin/bash
# Generates the admin password and its bcrypt hash once, in ~/.panelalpha
# (survives redeploys; ~/project does not). The users-seed service reads the hash.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/filegator"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'FILEGATOR_ADMIN_USER=admin\nFILEGATOR_ADMIN_PASSWORD=%s\n' \
        "$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)" > "${STORE}/admin.env")
    rm -f "${STORE}/admin.hash"
    echo "[panelalpha] filegator: generated the admin password"
fi
if [ ! -s "${STORE}/admin.hash" ]; then
    (umask 077; FILEGATOR_ADMIN_PASSWORD="$(sed -n 's/^FILEGATOR_ADMIN_PASSWORD=//p' "${STORE}/admin.env")" php -r '
        $p = getenv("FILEGATOR_ADMIN_PASSWORD");
        if ($p === false || strlen($p) < 12) { fwrite(STDERR, "FILEGATOR_ADMIN_PASSWORD missing or too short\n"); exit(1); }
        echo password_hash($p, PASSWORD_BCRYPT), "\n";' > "${STORE}/admin.hash")
fi
chmod 600 "${STORE}/admin.env" "${STORE}/admin.hash"
