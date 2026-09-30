#!/bin/bash
# After the clone, before `docker compose up`. Generates the session secret once
# into ~/.panelalpha/shiori/, which survives redeploys (~/project is wiped every
# deploy). The owner login is the engine's (`credentials:` in panelalpha.yaml),
# in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[panelalpha] shiori: $*" >&2; }

STORE="${HOME}/.panelalpha/shiori"
SECRET_ENV="${STORE}/secret.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    # Signs session tokens; without it Shiori picks a random one per start.
    ( umask 077; printf 'SHIORI_HTTP_SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${SECRET_ENV}" )
    say "generated SHIORI_HTTP_SECRET_KEY"
fi

chmod 600 "${SECRET_ENV}"
say "prepare complete"
