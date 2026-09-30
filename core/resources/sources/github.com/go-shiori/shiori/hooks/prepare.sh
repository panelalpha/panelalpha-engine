#!/bin/bash
# After the clone, before `docker compose up`. Generates the session secret and
# the owner password once into ~/.panelalpha/shiori/, which survives redeploys
# (~/project is wiped every deploy).
set -e
cd ~/project

say() { echo "[panelalpha] shiori: $*" >&2; }

STORE="${HOME}/.panelalpha/shiori"
SECRET_ENV="${STORE}/secret.env"
ADMIN_ENV="${STORE}/admin.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    # Signs session tokens; without it Shiori picks a random one per start.
    ( umask 077; printf 'SHIORI_HTTP_SECRET_KEY=%s\n' "$(openssl rand -hex 32)" > "${SECRET_ENV}" )
    say "generated SHIORI_HTTP_SECRET_KEY"
fi

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        printf 'SHIORI_ADMIN_USER=admin\nSHIORI_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
Shiori owner for this account
=============================

  username: admin
  password: ${ADMIN_PASSWORD}

Created on the first deploy; a redeploy never resets it. The upstream default
shiori/gopher account is deleted before the site opens. Add users in the web UI
(Settings > Accounts). Bookmarks, archives and shiori.db live on the named
volume shiori-data.
NOTE_EOF
    )
    say "owner credentials written to ${NOTE}"
fi
chmod 600 "${SECRET_ENV}" "${ADMIN_ENV}" "${NOTE}" 2>/dev/null || true
say "prepare complete"
