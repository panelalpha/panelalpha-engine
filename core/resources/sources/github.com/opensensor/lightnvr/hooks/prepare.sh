#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/lightnvr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# 24 hex chars: LightNVR keeps [web] password in a 32-byte buffer and
# silently truncates anything longer than 31 characters.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'LIGHTNVR_ADMIN_USER=admin\nLIGHTNVR_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 12)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

chmod +r panelalpha-seed.sh
