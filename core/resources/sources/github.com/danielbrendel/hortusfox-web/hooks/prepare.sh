#!/bin/bash
# Generates the MariaDB passwords once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The admin login is the engine's (`credentials:` in
# panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before this hook. The database volume outlives the
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
    # Hex only: the image's entrypoint pastes it into SQL and a php -r string.
    printf 'DB_PASSWORD=%s\n' "$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
# An older deploy kept the admin password here too; the engine adopted it.
sed -i '/^APP_ADMIN_PASSWORD=/d' "${STORE}/app.env"
chmod 600 "${STORE}"/*.env
