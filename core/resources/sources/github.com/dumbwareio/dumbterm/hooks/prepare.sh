#!/bin/bash
# Generates the PIN once, in ~/.panelalpha (survives redeploys; ~/project does
# not). DumbTerm is a root shell in a browser: upstream's compose falls back to
# the PIN 1234.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/dumbterm"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/pin.env" ]; then
    # Digits only (the login form has one numeric box per digit); 20 is ~66 bits.
    pin="$(tr -dc '0-9' < /dev/urandom | head -c 20)"
    (umask 077; printf 'DUMBTERM_PIN=%s\n' "$pin" > "${STORE}/pin.env")
fi
chmod 600 "${STORE}/pin.env"

chmod +r panelalpha/dumbterm-proxy.conf.template
