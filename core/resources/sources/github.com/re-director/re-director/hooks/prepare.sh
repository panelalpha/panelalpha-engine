#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs: generate the admin password
# once into a place a redeploy will not wipe, and make sure the compose env_file
# exists.
set -e
cd ~/project

say() { echo "[re-director] $*" >&2; }

# ~/.panelalpha survives a deploy; ~/project is emptied every time (engine#173),
# so the admin password must live here or it would be regenerated on every
# rebuild. The SQLite db on the /data volume already holds the created user's
# bcrypt hash, so this file is really the operator's copy of the password plus
# the value the seeder replays into /setup on the very first deploy.
STORE_DIR="${HOME}/.panelalpha/re-director"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ADMIN_ENV}" ]; then
    # base64 stripped of +/= so a POSIX env file reads it back verbatim.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${ADMIN_ENV}" <<EOF
# Written once by PanelAlpha and never regenerated. The 'seed' one-shot replays
# these into the web /setup form on the first deploy; afterwards the admin lives
# in /data/sqlite.db on the named volume and this file is just the record of it.
RD_ADMIN_USERNAME=admin
RD_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
re:Director on this account
===========================

re:Director manages HTTP redirects. Point any domain at this app and add a
redirect for it; managing redirects is behind a login. Auth is enabled by this
recipe (upstream ships it off by default).

ADMIN LOGIN (created once, on the first deploy)
  URL:      <this account's URL>/login
  Username: admin
  Password: ${ADMIN_PASSWORD}

  Change it from the app after first login if you like; a redeploy will not
  reset it (the user lives in /data/sqlite.db on a named volume, and the seeder
  only runs when no user exists yet).

DATA
  Redirects and the admin user are in /data/sqlite.db on a named Docker volume,
  which survives redeploy and storage reclaim. The generated password above is
  kept in ${STORE_DIR} (0600). Do not delete this directory.
EOF
    )
    say "admin password written to ${ADMIN_ENV}; notes in ${NOTE}"
else
    say "reusing the admin password in ${ADMIN_ENV}"
fi

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even before the platform writes it, so `docker compose up` does not
# abort on a missing file. The account's env_vars are merged in and win.
touch .env
say "prepare complete"
