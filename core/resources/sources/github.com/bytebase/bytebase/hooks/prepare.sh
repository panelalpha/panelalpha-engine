#!/bin/bash
# Runs in the account shell after the clone and after overrides/ are in place,
# before `docker compose up`. Bytebase has no env/flag to seed the first admin
# (the only server knobs are --data/--port/--external-url), and its setup screen
# makes the FIRST visitor the workspace owner. So this hook generates an admin
# password once and keeps it somewhere a redeploy will not wipe; the `bootstrap`
# compose service then claims that owner over Bytebase's own API the instant the
# server is healthy, before any visitor can, using this password.
set -e

say() { echo "[bytebase] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The password must live here and stay stable: on a redeploy the
# admin already exists in the persisted volume, and the bootstrap logs in with
# this same password to confirm it, so a regenerated password would no longer
# match the account in the database.
STORE_DIR="${HOME}/.panelalpha/bytebase"
ENV_FILE="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"
ADMIN_EMAIL="admin@bytebase.local"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_FILE}" ]; then
    # hex only: reads back cleanly from an env file and embeds in a JSON body
    # without escaping (no quotes, backslashes or $). Default password policy is
    # min length 8 with no complexity rule, so this passes.
    ADMIN_PASSWORD="$(openssl rand -hex 24)"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. The bootstrap service
# uses these to claim the first-user workspace admin over Bytebase's API before
# any visitor can, and to recognise on later deploys that the admin already
# exists (it logs in with this password). Do not delete or change it.
BB_ADMIN_EMAIL=${ADMIN_EMAIL}
BB_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Bytebase on this account
========================

Bytebase is a database DevOps / schema-migration console. All projects,
database instances and migration history are behind a login with role-based
access control.

ADMIN LOGIN (workspace owner, seeded automatically on the first deploy)
  URL:      <this account's URL>/
  Email:    ${ADMIN_EMAIL}
  Password: ${ADMIN_PASSWORD}

  This account is claimed automatically the moment the server is healthy, so the
  usual "first visitor becomes the admin" window is closed. Change the password
  from the app after first login if you like; a redeploy will not reset it (the
  workspace lives in the embedded Postgres on a named volume, and the bootstrap
  only creates the admin when the volume is empty).

KNOWN LIMITATION -- self-service signup stays open
  Bytebase Community Edition cannot disable self-service signup (it is a paid
  TEAM feature). Strangers can therefore create an account, BUT a self-registered
  user gets NO workspace role: they cannot see, read or create any project,
  database instance or migration, and cannot self-elevate. They can only see the
  list of user emails. To fully lock signup down, upgrade the license and turn
  off self-service signup in Settings > General.

DATA
  The whole workspace (admin user, RBAC, projects, database instances and
  migration history) lives in Bytebase's embedded PostgreSQL at
  /var/opt/bytebase on a named Docker volume, which survives redeploy and
  storage reclaim. The generated password above is kept in ${STORE_DIR} (0600).
  Do not delete this directory.
EOF
    )
    chmod 600 "${ENV_FILE}" "${NOTE}"
    say "generated admin password -> ${ENV_FILE}; notes in ${NOTE}"
else
    say "reusing the admin password in ${ENV_FILE}"
fi

# Pre-pull the pinned images so `compose up` starts fast and an image NotFound
# surfaces here (best effort).
docker pull bytebase/bytebase:3.23.0 >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
say "prepare complete"
