#!/bin/bash
# Runs in the account shell after the clone and after overrides/ are in place,
# before `docker compose up`. A fresh CloudBeaver boots into configuration mode
# and makes the FIRST visitor the admin. CloudBeaver's built-in automatic
# configuration reads CB_SERVER_NAME/CB_ADMIN_NAME/CB_ADMIN_PASSWORD from the
# environment on first boot to seed the admin and mark itself configured, so
# this hook generates that admin password once and keeps it somewhere a redeploy
# will not wipe; the compose file passes it to the server as CB_ADMIN_PASSWORD.
set -e

say() { echo "[cloudbeaver] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The password must live here and stay stable: on a redeploy the
# server is already configured (the workspace named volume has its runtime
# config), the CB_* variables are ignored, and the admin created on the first
# deploy with this password stays valid -- so a regenerated password would just
# no longer match the account in the H2 database.
STORE_DIR="${HOME}/.panelalpha/cloudbeaver"
ENV_FILE="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"
ADMIN_NAME="cbadmin"
SERVER_NAME="CloudBeaver"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_FILE}" ]; then
    # CloudBeaver's default password policy is minLength 8, requireMixedCase
    # true, minNumberCount 1 (minSymbolCount 0). This value is guaranteed to
    # satisfy it: a "Cb" prefix (upper + lower), 36 hex chars (lowercase +
    # digits) and an "X7" suffix (upper + digit). Alphanumeric only, so it reads
    # back cleanly from an env file and embeds in a JSON body with no escaping.
    ADMIN_PASSWORD="Cb$(openssl rand -hex 18)X7"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. The CloudBeaver server
# uses these on its first boot (automatic configuration) to create the admin and
# mark itself configured; on later deploys the server is already configured and
# ignores them. Do not delete or change them.
CB_SERVER_NAME=${SERVER_NAME}
CB_ADMIN_NAME=${ADMIN_NAME}
CB_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
CloudBeaver on this account
===========================

CloudBeaver is a web-based database manager (the browser version of DBeaver).
The console and GraphQL API are behind a login; anonymous access is turned off.

ADMIN LOGIN (seeded automatically on the first deploy)
  URL:      <this account's URL>/
  Login:    ${ADMIN_NAME}
  Password: ${ADMIN_PASSWORD}

  The admin is created automatically on the first boot, so the usual
  "first visitor becomes the admin" setup wizard never opens to the public.
  Change the password from the app after first login if you like; a redeploy
  will not reset it (the workspace lives in an embedded H2 database on a named
  volume, and the server only auto-configures when that volume is empty).

DATA
  The whole workspace (admin user, teams/roles, saved database connections and
  settings) lives in CloudBeaver's embedded H2 database under
  /opt/cloudbeaver/workspace/.data on a named Docker volume, which survives
  redeploy and storage reclaim. The generated password above is kept in
  ${STORE_DIR} (0600). Do not delete this directory.

NOTE
  CloudBeaver connects to OTHER databases you configure -- that is its purpose.
  The security boundary is the CloudBeaver login itself, which is enforced here.
EOF
    )
    chmod 600 "${ENV_FILE}" "${NOTE}"
    say "generated admin password -> ${ENV_FILE}; notes in ${NOTE}"
else
    say "reusing the admin password in ${ENV_FILE}"
fi

# Pre-pull the pinned images so `compose up` starts fast and an image NotFound
# surfaces here (best effort).
docker pull dbeaver/cloudbeaver:26.2.1 >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
say "prepare complete"
