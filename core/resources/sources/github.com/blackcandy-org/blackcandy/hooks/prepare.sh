#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates SECRET_KEY_BASE
# once into ~/.panelalpha (a redeploy empties ~/project). The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[blackcandy] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/blackcandy"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    SECRET_KEY_BASE="$(openssl rand -hex 64)"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy. A new
# SECRET_KEY_BASE logs everyone out.
SECRET_KEY_BASE=${SECRET_KEY_BASE}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi
# An older deploy kept the admin login here too; the engine adopted it.
sed -i '/^BC_ADMIN_\(EMAIL\|PASSWORD\)=\|^# Replaces the seeded admin/d' "${APP_ENV}"

# The compose lists .env as an env_file; make sure it exists.
touch .env
