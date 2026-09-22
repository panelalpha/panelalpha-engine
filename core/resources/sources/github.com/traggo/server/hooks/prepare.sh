#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: generate the first-admin password once into a place a redeploy will
# not wipe, and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[traggo] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The admin password written under ~/project would be regenerated
# on every rebuild; on a rebuild that also reuses the SQLite volume the app
# would ignore it (user already exists) and the recorded password would no
# longer match. So it must live here and stay stable.
STORE_DIR="${HOME}/.panelalpha/traggo"
ENV_FILE="${STORE_DIR}/traggo.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
# base64 stripped of +/=/newline so a POSIX env file reads it back verbatim.
if [ ! -f "${ENV_FILE}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. Traggo seeds its first
# admin from this on the very first boot (when the SQLite volume is empty);
# afterwards the admin lives in the DB on the named volume and this is just the
# record of the password. Do not delete it.
TRAGGO_DEFAULT_USER_PASS=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Traggo on this account
======================

Traggo is a tag-based time tracker. Everything is behind a login; there is no
public self-registration (users are created by an admin from the UI).

ADMIN LOGIN (created once, on the first deploy)
  URL:      <this account's URL>/
  Username: admin
  Password: ${ADMIN_PASSWORD}

  This replaces Traggo's shipped default of admin/admin, which is never valid on
  this deploy. Change it from the app after first login if you like; a redeploy
  will not reset it (the user lives in the SQLite DB on a named volume, and the
  bootstrap only runs when no user exists yet).

DATA
  The SQLite database (admin user + all time spans, tags and dashboards) is at
  /opt/traggo/data/traggo.db on a named Docker volume, which survives redeploy
  and storage reclaim. The generated password above is kept in ${STORE_DIR}
  (0600). Do not delete this directory.
EOF
    )
    chmod 600 "${ENV_FILE}" "${NOTE}"
    say "admin password written to ${ENV_FILE}; notes in ${NOTE}"
else
    say "reusing the admin password in ${ENV_FILE}"
fi

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even when the platform has not written one yet, so `docker compose up`
# does not abort on a missing file. The account's env_vars are merged in.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull traggo/server:0.8.3 >/dev/null 2>&1 || true
say "prepare complete"
