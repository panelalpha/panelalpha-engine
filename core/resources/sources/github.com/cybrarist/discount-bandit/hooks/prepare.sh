#!/bin/bash
# Generates the Laravel APP_KEY once into ~/.panelalpha: it encrypts stored
# data and sessions, so it must survive redeploys (~/project does not).
set -e

STORE="${HOME}/.panelalpha/discount-bandit"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
