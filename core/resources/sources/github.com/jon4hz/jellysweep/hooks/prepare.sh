#!/bin/bash
# Session key and starter config, created once in ~/.panelalpha (survives redeploys).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/jellysweep"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/session.env" ]; then
    (umask 077; printf 'JELLYSWEEP_SESSION_KEY=%s\n' "$(openssl rand -hex 32)" > "${STORE}/session.env")
fi
chmod 600 "${STORE}/session.env"

# Library names must match the Jellyfin libraries; the customer edits this file.
if [ ! -f "${STORE}/config.yml" ]; then
    cp jellysweep-config.yml "${STORE}/config.yml"
    chmod 600 "${STORE}/config.yml"
fi
