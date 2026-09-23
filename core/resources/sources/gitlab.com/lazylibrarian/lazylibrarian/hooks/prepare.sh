#!/bin/bash
# Seed a persistent, auth-enabled datadir for LazyLibrarian before first boot.
#
# LazyLibrarian keeps its whole state in --datadir, and ships with web auth OFF
# (HTTP_USER/HTTP_PASS empty) — a public deploy with no auth is world-
# controllable. This hook writes config.ini with BASIC auth turned on, into the
# account's persistent ~/.panelalpha (the only rebuild-surviving writable dir;
# ~/project is wiped every redeploy, engine#173). The password and API key are
# generated ONCE and reused on every redeploy — never regenerated, never placed
# in ~/project.
set -e

DATA_DIR="${HOME}/.panelalpha/lazylibrarian"
mkdir -p "${DATA_DIR}"
CONFIG="${DATA_DIR}/config.ini"

# env_file: .env is declared by the dockerfile strategy; make sure it exists.
touch "${HOME}/project/.env" 2>/dev/null || true

if [ -f "${CONFIG}" ]; then
    # Redeploy: reuse the existing credentials and state untouched.
    echo "LazyLibrarian: existing config.ini found, reusing credentials."
    exit 0
fi

HTTP_USER="admin"
HTTP_PASS="$(openssl rand -hex 16)"
API_KEY="$(openssl rand -hex 16)"   # 32 hex chars — LazyLibrarian requires len==32

umask 077
cat > "${CONFIG}" <<EOF
[General]
AUTH_TYPE = BASIC
LAUNCH_BROWSER = 0

[API]
API_ENABLED = 1
API_KEY = ${API_KEY}

[WebServer]
HTTP_PORT = 5299
HTTP_HOST = 0.0.0.0
HTTP_USER = ${HTTP_USER}
HTTP_PASS = ${HTTP_PASS}
HTTP_ROOT =
HTTP_PROXY = 0
EOF
chmod 600 "${CONFIG}"

# Owner-recoverable credential record — 0600, inside the persistent datadir,
# never in ~/project.
cat > "${DATA_DIR}/panelalpha-credentials.txt" <<EOF
LazyLibrarian web login (BASIC auth)
  username: ${HTTP_USER}
  password: ${HTTP_PASS}
  api_key:  ${API_KEY}
EOF
chmod 600 "${DATA_DIR}/panelalpha-credentials.txt"

echo "LazyLibrarian: seeded config.ini with BASIC auth (user '${HTTP_USER}')."
