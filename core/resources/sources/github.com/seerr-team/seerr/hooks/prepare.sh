#!/bin/bash
# Generate the owner password once, where a redeploy will not wipe it
# (~/project is emptied on every deploy, engine#173).
set -e
cd ~/project

say() { echo "[seerr] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/seerr"
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
        printf 'SEERR_ADMIN_PASSWORD=%s\nSEERR_ADMIN_TAG=%s\n' \
            "${ADMIN_PASSWORD}" "${ADMIN_TAG}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
Seerr on this account
=====================

The setup wizard is closed: a local owner account was created on the first
deploy and setup was marked complete, so no visitor can claim the instance by
signing in with Plex, Jellyfin or Emby.

OWNER LOGIN ("Sign in with Seerr" / email and password on the sign-in page)
  URL:      <this account's URL>/login
  Email:    admin-${ADMIN_TAG}@<this account's domain>
  Password: ${ADMIN_PASSWORD}

MEDIA SERVER
  Plex is the default. Choose before connecting anything; the first choice
  sticks once a server or account is linked.

  Plex: set the project env variable SEERR_OWNER_EMAIL to the email of your
  Plex account and redeploy (Seerr has no screen to change an email); then
  "Sign in with Plex" links that account to the owner and Settings > Plex can
  reach your server.

  Jellyfin/Emby: set SEERR_MEDIA_SERVER=jellyfin (or emby),
  SEERR_JELLYFIN_URL (e.g. https://jellyfin.example.com) and
  SEERR_JELLYFIN_API_KEY (Jellyfin Dashboard > API Keys), then redeploy. Link
  your own account under Profile > Linked Accounts.

A redeploy does not reset the password. The database and settings.json live
on the seerr-config volume, which survives redeploys.
NOTE_EOF
    )
    say "owner credentials written to ${STORE_DIR}"
else
    say "reusing the owner credentials in ${STORE_DIR}"
fi

touch .env
