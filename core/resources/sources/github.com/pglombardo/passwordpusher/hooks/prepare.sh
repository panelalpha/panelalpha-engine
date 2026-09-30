#!/bin/bash
# Generates the per-account secrets and admin password once, in ~/.panelalpha
# (survives redeploys; ~/project does not). Regenerating PWPUSH_MASTER_KEY
# would make every stored push undecryptable.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/pwpush"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    ADMIN_PASSWORD="$(openssl rand -hex 12)"
    (
        umask 077
        cat > "${STORE}/app.env" <<ENV
# Written once by PanelAlpha; do not delete or regenerate.
SECRET_KEY_BASE=$(openssl rand -hex 64)
PWPUSH_MASTER_KEY=$(openssl rand -hex 32)
PA_ADMIN_EMAIL=admin@example.com
PA_ADMIN_PASSWORD=${ADMIN_PASSWORD}
ENV
        cat > "${STORE}/credentials.txt" <<NOTE
Password Pusher administrator (seeded on the first deploy)
  Login:    <this account's URL>/users/sign_in
  Email:    admin@example.com
  Password: ${ADMIN_PASSWORD}
  Admin dashboard: /admin (administrators only)

Anyone can create a push without an account (the app's purpose); a push is
readable only through its secret URL. Open signup is disabled.
NOTE
    )
    echo "[pwpush] secrets written to ${STORE}" >&2
fi
chmod 600 "${STORE}/app.env" "${STORE}/credentials.txt"

# The compose file lists .env; make sure it exists.
touch .env
