#!/bin/bash
# After the clone, before `docker compose up`. Generates JWT_SECRET once into
# ~/.panelalpha/invio/, which survives redeploys. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e

say() { echo "[panelalpha] invio: $*" >&2; }

STORE="${HOME}/.panelalpha/invio"
SECRET_ENV="${STORE}/secret.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${SECRET_ENV}" ]; then
    (umask 077; printf 'JWT_SECRET=%s\n' "$(openssl rand -hex 32)" > "${SECRET_ENV}")
    say "JWT secret written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi
# An older deploy kept the login here too; the engine adopted it.
sed -i '/^ADMIN_\(USER\|PASS\)=/d' "${SECRET_ENV}"
chmod 600 "${SECRET_ENV}"
say "prepare complete"
