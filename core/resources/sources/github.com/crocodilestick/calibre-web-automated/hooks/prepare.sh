#!/bin/bash
# Runs after the clone, before `docker compose up`. Generates this account's
# admin password once into ~/.panelalpha (a redeploy empties ~/project).
set -e
cd ~/project

say() { echo "[cwa] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/calibre-web-automated"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # Calibre-Web's default policy wants 8+ chars with a digit, upper, lower and
    # a special character; the random part is alphanumeric, the suffix covers the rest.
    ADMIN_PASSWORD="$(openssl rand -base64 30 | tr -d '\n=/+' | cut -c1-24)-Q7k"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and reused on every redeploy.
PUID=$(id -u)
PGID=$(id -g)
# Replaces Calibre-Web's shipped admin/admin123 before the port opens.
CWA_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Calibre-Web Automated on this account
=====================================

ADMIN LOGIN (the shipped admin/admin123 default is replaced before first start)
  Username: admin
  Password: ${ADMIN_PASSWORD}

Changing the password in the app is kept across redeploys. Settings, users and
the book library live on the Docker named volumes 'config' and 'library' and
survive a redeploy. Books dropped into the 'ingest' volume are imported and
then removed from it.
EOF
    )
    say "admin password written to ${STORE_DIR}; notes in ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The compose lists .env as an env_file; make sure it exists.
touch .env
