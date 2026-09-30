#!/bin/bash
# Generates SECRET once, in ~/.panelalpha (survives redeploys; ~/project does
# not): it keys the password hashes, so a new one would lock every account out.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/wikidocs"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'MODE=public\nSECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
    echo "[panelalpha] wikidocs: generated SECRET into ${STORE}/app.env" >&2
fi
chmod 600 "${STORE}/app.env"

# The compose file lists .env; the project's environment variables merge into it.
touch .env
