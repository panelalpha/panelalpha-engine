#!/bin/bash
# config.yml (upstream's example, as the README's step 1) and SECRET_KEY, created
# once in ~/.panelalpha: ~/project is emptied on every deploy.
set -e
STORE="${HOME}/.panelalpha/homehub"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/config.yml" ]; then
    cp "${HOME}/project/config-example.yml" "${STORE}/config.yml"
fi
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077; printf 'SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
# Read by the container's user (root in the image) through a bind mount.
chmod 644 "${STORE}/config.yml"
