#!/bin/bash
# Generates the login credentials once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The seed service creates the user from them.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/rdtclient"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    # Alphanumeric only: the seed puts it into JSON without escaping.
    pw="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    (umask 077; printf 'RDTCLIENT_ADMIN_USER=admin\nRDTCLIENT_ADMIN_PASSWORD=%s\n' "$pw" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

chmod +r panelalpha/rdtclient-seed.sh panelalpha/rdtclient-proxy.conf
