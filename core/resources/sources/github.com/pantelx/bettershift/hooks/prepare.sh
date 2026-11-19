#!/bin/bash
# Generates BetterShift's session secret once, in ~/.panelalpha (survives
# redeploys; ~/project does not): a new one would sign everyone out.
set -e
STORE="${HOME}/.panelalpha/bettershift"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secret.env" ]; then
    (umask 077; printf 'BETTERSHIFT_AUTH_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/secret.env")
    echo "[panelalpha] bettershift: generated the session secret into ${STORE}/secret.env" >&2
fi
chmod 600 "${STORE}/secret.env"
