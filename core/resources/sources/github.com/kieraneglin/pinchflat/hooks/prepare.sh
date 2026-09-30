#!/bin/bash
# Generates SECRET_KEY_BASE once, in ~/.panelalpha (survives redeploys;
# ~/project does not). Without it the image signs sessions with a key that is
# hard-coded in config/runtime.exs, the same on every install.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/pinchflat"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -hex 64)" > "${STORE}/app.env")
    echo "[panelalpha] pinchflat: generated SECRET_KEY_BASE into ${STORE}/app.env" >&2
fi
chmod 600 "${STORE}/app.env"

# The compose file lists .env; the project's environment variables merge into it.
touch .env
