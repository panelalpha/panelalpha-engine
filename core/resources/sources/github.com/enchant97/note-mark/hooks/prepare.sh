#!/bin/bash
# After the clone, before `docker compose up`. Generates the token secret and
# the owner password once into ~/.panelalpha/notemark/, which survives redeploys
# (~/project is wiped every deploy).
set -e
cd ~/project

say() { echo "[panelalpha] notemark: $*" >&2; }

STORE="${HOME}/.panelalpha/notemark"
SECRET_ENV="${STORE}/secret.env"
ADMIN_ENV="${STORE}/admin.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    # Signs every login token; regenerating it would log every session out.
    ( umask 077; printf 'AUTH_TOKEN__SECRET=%s\n' "$(openssl rand -base64 48 | tr -d '\n')" > "${SECRET_ENV}" )
    say "generated AUTH_TOKEN__SECRET"
fi

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        printf 'NOTEMARK_ADMIN_USER=admin\nNOTEMARK_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<EOF
Note Mark owner for this account
================================

  username: admin
  password: ${ADMIN_PASSWORD}

Created on the first deploy; a redeploy never resets it. Public sign-up is
disabled. Add users with the CLI inside the app container:
  docker compose -p project exec app /note-mark user add -u <name> -p <password>
Notes and the database live on the named volume notemark-data.
EOF
    )
    say "owner credentials written to ${NOTE}"
fi
chmod 600 "${SECRET_ENV}" "${ADMIN_ENV}" "${NOTE}" 2>/dev/null || true

# Compose interpolates ${NOTEMARK_IMAGE} from here; the account's env_vars merge in.
touch .env
say "prepare complete"
