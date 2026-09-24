#!/bin/bash
# The Super Admin credentials, generated once into ~/.panelalpha/listmonk/ --
# the one directory a redeploy (which empties ~/project) cannot reach.
set -e

DATA_HOME="${HOME}/.panelalpha/listmonk"
ADMIN_ENV="${DATA_HOME}/admin.env"
NOTE="${DATA_HOME}/credentials.txt"

say() { echo "[panelalpha] listmonk: $*" >&2; }

mkdir -p "${DATA_HOME}"
chmod 700 "${DATA_HOME}"

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_USER=admin
    # Letters and digits only: it travels in a form body and an env_file.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${ADMIN_ENV}" <<ENV
PA_ADMIN_USER=${ADMIN_USER}
PA_ADMIN_PASSWORD=${ADMIN_PASSWORD}
ENV
        cat > "${NOTE}" <<NOTE
listmonk Super Admin for this account
=====================================

  sign in at  https://<your domain>/admin/
  username    ${ADMIN_USER}
  password    ${ADMIN_PASSWORD}

PanelAlpha created this Super Admin before listmonk was reachable, so its
first-time setup page was never offered to anyone else. Change the password
under Admin -> Users. Nothing here is updated afterwards; the value in
listmonk wins.

If this listmonk already had a user when this file was written, nothing was
created and these credentials were never used.
NOTE
    )
    say "Super Admin credentials written to ${NOTE}"
fi
chmod 600 "${ADMIN_ENV}" "${NOTE}" 2>/dev/null || true
