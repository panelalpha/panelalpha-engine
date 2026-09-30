#!/bin/bash
# Runs after the clone, before `docker compose up`. Writes PUID/PGID once into
# ~/.panelalpha (a redeploy empties ~/project). The admin login is the engine's
# (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[cwa] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/calibre-web-automated"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy.
PUID=$(id -u)
PGID=$(id -g)
EOF
    )
    say "settings written to ${STORE_DIR}"
else
    say "reusing the settings in ${STORE_DIR}"
fi
# An older deploy kept the admin password here too; the engine adopted it.
sed -i '/^CWA_ADMIN_PASSWORD=\|^# Replaces Calibre-Web/d' "${APP_ENV}"

# The compose lists .env as an env_file; make sure it exists.
touch .env
