#!/bin/bash
# Generates the web UI login once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Quasarr checks USER/PASS from its environment.
set -e

STORE="${HOME}/.panelalpha/quasarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/auth.env" ]; then
    (umask 077; printf 'USER=admin\nPASS=%s\n' "$(openssl rand -hex 16)" > "${STORE}/auth.env")
fi
chmod 600 "${STORE}/auth.env"
