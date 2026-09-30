#!/bin/bash
# Generate the owner password once, where a redeploy will not wipe it
# (~/project is emptied on every deploy, engine#173).
set -e
cd ~/project

say() { echo "[overseerr] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/overseerr"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # Random local part: a Plex account registered with the owner's exact email
    # could otherwise sign in as the owner until a Plex account is linked.
    ADMIN_TAG="$(openssl rand -hex 5)"
    (
        umask 077
        printf 'OVERSEERR_ADMIN_PASSWORD=%s\nOVERSEERR_ADMIN_TAG=%s\n' \
            "${ADMIN_PASSWORD}" "${ADMIN_TAG}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
Overseerr on this account
=========================

The first-run wizard is closed: a local owner account was created on the
first deploy and setup was marked complete, so no visitor can claim the
instance by signing in with Plex.

OWNER LOGIN ("Use your Overseerr account" on the sign-in page)
  URL:      <this account's URL>/login
  Email:    admin-${ADMIN_TAG}@<this account's domain>
  Password: ${ADMIN_PASSWORD}

CONNECTING PLEX
  Overseerr has no screen to change an email. Set the project env variable
  OVERSEERR_OWNER_EMAIL to the email of your Plex account and redeploy: the
  owner takes that email (only while no Plex account is linked). Then use
  "Sign in with Plex" with that account; Overseerr links it to the owner, and
  Settings > Plex can reach your server. The password above keeps working.

A redeploy does not reset the password. The database and settings.json live
on the overseerr-config volume, which survives redeploys.
NOTE_EOF
    )
    say "owner credentials written to ${STORE_DIR}"
else
    say "reusing the owner credentials in ${STORE_DIR}"
fi

touch .env
