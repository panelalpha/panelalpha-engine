#!/bin/bash
# Generates the PIN once, in ~/.panelalpha (survives redeploys; ~/project does
# not). Upstream's .env.example sets 1234.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/dumbdo"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/pin.env" ]; then
    # 10 digits, the longest DumbDo accepts. It pads guesses with trailing
    # zeros before comparing, so the last digit is never 0.
    pin="$(tr -dc '0-9' < /dev/urandom | head -c 9)$(tr -dc '1-9' < /dev/urandom | head -c 1)"
    (umask 077; printf 'DUMBDO_PIN=%s\n' "$pin" > "${STORE}/pin.env")
fi
chmod 600 "${STORE}/pin.env"

# The engine copies .env.example (DUMBDO_PIN=1234) into .env; nothing here reads it.
printf '# DumbDo takes its settings from docker-compose.yml and ~/.panelalpha/dumbdo/pin.env\n' > .env

chmod +r panelalpha/dumbdo-proxy.conf.template
