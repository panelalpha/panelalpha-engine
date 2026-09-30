#!/bin/bash
# Generates APP_KEY, the MariaDB passwords and the admin password once, in
# ~/.panelalpha (survives redeploys; ~/project does not). Regenerating any of
# them against the surviving volumes would lock Snipe-IT out of its data.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/snipeit"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/db.env" ]; then
    printf 'MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    # Laravel accepts only base64:<32 bytes> for AES-256-CBC.
    printf 'APP_KEY=base64:%s\nDB_PASSWORD=%s\n' \
        "$(openssl rand -base64 32)" \
        "$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
if [ ! -f "${STORE}/admin.env" ]; then
    printf 'SNIPEIT_ADMIN_USER=admin\nSNIPEIT_ADMIN_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" > "${STORE}/admin.env"
fi
chmod 600 "${STORE}"/*.env
