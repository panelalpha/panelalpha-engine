#!/bin/bash
# After the clone, before `docker compose up`. Generates the admin password once
# into ~/.panelalpha/podfetch/, which survives redeploys (~/project is wiped).
set -e

say() { echo "[panelalpha] podfetch: $*" >&2; }

STORE="${HOME}/.panelalpha/podfetch"
AUTH_ENV="${STORE}/auth.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${AUTH_ENV}" ]; then
    PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        printf 'BASIC_AUTH=true\nUSERNAME=admin\nPASSWORD=%s\n' "${PASSWORD}" > "${AUTH_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
PodFetch on this account
========================

Built-in authentication (BASIC_AUTH) is on; nothing is reachable without it.

ADMIN LOGIN
  Username: admin
  Password: ${PASSWORD}

The admin account is defined by USERNAME/PASSWORD in
~/.panelalpha/podfetch/auth.env and re-applied on every deploy: change the
password there and redeploy. Further users are added by the admin via
invites (Settings > User management).
NOTE_EOF
    )
    say "admin credentials written to ${NOTE}"
else
    say "reusing the credentials in ${STORE}"
fi
chmod 600 "${AUTH_ENV}" "${NOTE}"
say "prepare complete"
