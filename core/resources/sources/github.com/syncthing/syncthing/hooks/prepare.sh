#!/bin/bash
# Generates the GUI password once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/syncthing"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Read only by the seed service; Syncthing keeps a bcrypt hash in config.xml.
if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'SYNCTHING_GUI_USER=admin\nSYNCTHING_GUI_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/admin.env")
fi
chmod 600 "${STORE}/admin.env"

# The compose file lists .env; the seed script runs as the image's uid 1000.
touch .env
chmod +r panelalpha-seed.sh
