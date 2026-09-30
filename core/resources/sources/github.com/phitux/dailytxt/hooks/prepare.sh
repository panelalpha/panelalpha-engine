#!/bin/bash
# After the clone, before `docker compose up`. Generates the cookie secret once
# into ~/.panelalpha/dailytxt/, which survives redeploys (~/project is wiped).
# The first user's login and the admin-panel password are the engine's
# (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[panelalpha] dailytxt: $*" >&2; }

STORE="${HOME}/.panelalpha/dailytxt"
SECRET_ENV="${STORE}/secret.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"


if [ ! -f "${SECRET_ENV}" ]; then
    (
        umask 077
        printf 'SECRET_TOKEN=%s\n' "$(openssl rand -base64 32 | tr -d '\n')" > "${SECRET_ENV}"
    )
    say "cookie secret written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi
# An older deploy kept the admin-panel password here too; the engine's wins.
sed -i '/^ADMIN_PASSWORD=/d' "${SECRET_ENV}"
chmod 600 "${SECRET_ENV}"
say "prepare complete"
