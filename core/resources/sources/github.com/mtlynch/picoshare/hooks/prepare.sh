#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: generate the admin passphrase once into a place a redeploy will not
# wipe, and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[picoshare] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The passphrase written under ~/project would be regenerated on
# every rebuild, silently locking the owner out of an already-populated DB. So
# it must live here and stay stable.
STORE_DIR="${HOME}/.panelalpha/picoshare"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
# base64 stripped of +/=/newline so a POSIX env file reads it back verbatim and
# the value carries no character an HTTP body or a shell would mangle. 32 raw
# bytes -> ~43 chars of entropy for the admin gate.
if [ ! -f "${APP_ENV}" ]; then
    SHARED_SECRET="$(openssl rand -base64 32 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and never regenerated. PicoShare reads
# PS_SHARED_SECRET as the admin passphrase; main.go refuses to start if it is
# unset, so there is no open-admin state. Fed to the container as an env_file.
# Do not delete it -- it is the only copy of the admin passphrase.
PS_SHARED_SECRET=${SHARED_SECRET}
EOF
        cat > "${NOTE}" <<EOF
PicoShare on this account
=========================

PicoShare is a minimal file-sharing service: upload a file, get a permanent
shareable link. Downloading a file by its share link is PUBLIC by design -- the
links are meant to be handed out. UPLOADING and administration are private:
they require the admin passphrase below, so no stranger can store files on the
account or manage the share links.

ADMIN LOGIN (created once, on the first deploy)
  URL:        <this account's URL>/
  Passphrase: ${SHARED_SECRET}

  Open the site, click Log In, and enter this passphrase. It replaces the
  plaintext dummy secret upstream's compose example ships; there is no
  admin/admin equivalent here. A redeploy will not reset it.

DATA
  The SQLite database and the uploaded file bytes live under /data
  (/data/store.db) on a named Docker volume, which survives redeploy and storage
  reclaim. The passphrase above is kept in ${STORE_DIR} (0600). Do not delete
  this directory.
EOF
    )
    chmod 600 "${APP_ENV}" "${NOTE}"
    say "shared secret written to ${APP_ENV}; notes in ${NOTE}"
else
    say "reusing the shared secret in ${APP_ENV}"
fi

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even when the platform has not written one yet, so `docker compose up`
# does not abort on a missing file. The account's env_vars are merged in.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull mtlynch/picoshare:v1.5.4 >/dev/null 2>&1 || true
say "prepare complete"
