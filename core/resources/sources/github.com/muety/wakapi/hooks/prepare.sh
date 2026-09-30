#!/bin/bash
# Generates the password salt and the owner's credentials once, in
# ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/wakapi"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# The salt peppers every password hash: rolling it locks everyone out.
if [ ! -f "${STORE}/wakapi.env" ]; then
    (umask 077; echo "WAKAPI_PASSWORD_SALT=$(openssl rand -hex 32)" > "${STORE}/wakapi.env")
fi

# Read only by the seed service; the app never sees the owner's password.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'WAKAPI_ADMIN_USER=admin\nWAKAPI_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/wakapi.env" "${STORE}/admin.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha-seed.sh
