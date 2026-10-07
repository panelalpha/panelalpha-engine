#!/bin/bash
# Generates the PIN once, in ~/.panelalpha (survives redeploys; ~/project does
# not). Without a PIN DumbPad serves every note to anyone.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/dumbpad"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/pin.env" ]; then
    # 10 digits, the longest PIN DumbPad accepts (/^\d{4,10}$/).
    pin="$(tr -dc '0-9' < /dev/urandom | head -c 10)"
    (umask 077; printf 'DUMBPAD_PIN=%s\n' "$pin" > "${STORE}/pin.env")
fi
chmod 600 "${STORE}/pin.env"

# Upstream's .env.example is UTF-16 and the engine would copy it into a .env
# that compose rejects. Nothing here needs values from .env.
printf '# DumbPad takes its settings from docker-compose.yml and ~/.panelalpha/dumbpad/pin.env\n' > .env

chmod +r panelalpha/dumbpad-proxy.conf.template
