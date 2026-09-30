#!/bin/bash
# After the clone, before `docker compose up`. Generates the token secret once
# into ~/.panelalpha/notemark/, which survives redeploys (~/project is wiped
# every deploy). The owner's login is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[panelalpha] notemark: $*" >&2; }

STORE="${HOME}/.panelalpha/notemark"
SECRET_ENV="${STORE}/secret.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    # Signs every login token; regenerating it would log every session out.
    ( umask 077; printf 'AUTH_TOKEN__SECRET=%s\n' "$(openssl rand -base64 48 | tr -d '\n')" > "${SECRET_ENV}" )
    say "generated AUTH_TOKEN__SECRET"
fi

chmod 600 "${SECRET_ENV}"

# Compose interpolates ${NOTEMARK_IMAGE} from here; the account's env_vars merge in.
touch .env
say "prepare complete"
