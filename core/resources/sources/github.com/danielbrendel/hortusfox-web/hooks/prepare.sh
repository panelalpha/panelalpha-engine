#!/bin/bash
# Generates the MariaDB passwords and the admin password once, in ~/.panelalpha
# (survives redeploys; ~/project does not). The database volume outlives the
# checkout, so regenerating them would lock HortusFox out of its own data.
set -e

STORE="${HOME}/.panelalpha/hortusfox"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/db.env" ]; then
    printf 'MARIADB_PASSWORD=%s\nMARIADB_ROOT_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    # Hex only: the image's entrypoint pastes these into SQL and a php -r string.
    printf 'DB_PASSWORD=%s\nAPP_ADMIN_PASSWORD=%s\n' \
        "$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")" \
        "$(openssl rand -hex 16)" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
