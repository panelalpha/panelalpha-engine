#!/bin/bash
# Generates the PIN once, in ~/.panelalpha (survives redeploys; ~/project does
# not). Upstream's compose file hardcodes 123456.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/dumbdrop"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/pin.env" ]; then
    # 10 digits, the longest DumbDrop accepts.
    pin="$(tr -dc '0-9' < /dev/urandom | head -c 10)"
    (umask 077; printf 'DUMBDROP_PIN=%s\n' "$pin" > "${STORE}/pin.env")
fi
chmod 600 "${STORE}/pin.env"

# The engine copies .env.example (an empty DUMBDROP_PIN) into .env; nothing here reads it.
printf '# DumbDrop takes its settings from docker-compose.yml and ~/.panelalpha/dumbdrop/pin.env\n' > .env

chmod +r panelalpha/dumbdrop-proxy.conf.template
