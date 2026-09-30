#!/bin/bash
# Seed a persistent, auth-enabled datadir for LazyLibrarian before first boot.
#
# LazyLibrarian keeps its whole state in --datadir, and ships with web auth OFF
# (HTTP_USER/HTTP_PASS empty) — a public deploy with no auth is world-
# controllable. This hook writes config.ini with BASIC auth turned on, into the
# account's persistent ~/.panelalpha (the only rebuild-surviving writable dir;
# ~/project is wiped every redeploy, engine#173). The login is the engine's
# (`credentials:` in panelalpha.yaml, ~/.panelalpha/app-credentials.env, written
# before this hook runs); the API key is generated ONCE here. Neither is ever
# placed in ~/project.
set -e

DATA_DIR="${HOME}/.panelalpha/lazylibrarian"
mkdir -p "${DATA_DIR}"
CONFIG="${DATA_DIR}/config.ini"

# env_file: .env is declared by the dockerfile strategy; make sure it exists.
touch "${HOME}/project/.env" 2>/dev/null || true

if [ -f "${CONFIG}" ]; then
    # Once, for an account the older recipe seeded: while config.ini still holds
    # the password its record names, move it to the engine's. The record goes.
    OLD="${DATA_DIR}/panelalpha-credentials.txt"
    if [ -f "${OLD}" ]; then
        old_pw="$(sed -n 's/^  password: //p' "${OLD}")"
        # LazyLibrarian rewrites the file with lower-case keys (http_pass).
        if [ -n "${old_pw}" ] && grep -qiE "^http_pass *= *${old_pw}\$" "${CONFIG}"; then
            set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
            sed -i -E "s/^(http_pass) *=.*/\1 = ${LAZYLIBRARIAN_ADMIN_PASSWORD}/I" "${CONFIG}"
            echo "LazyLibrarian: moved the seeded login to the engine's credentials."
        fi
        rm -f "${OLD}"
    fi
    # Redeploy: reuse the existing credentials and state untouched.
    echo "LazyLibrarian: existing config.ini found, reusing credentials."
    exit 0
fi

set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
HTTP_USER="${LAZYLIBRARIAN_ADMIN_USER}"
HTTP_PASS="${LAZYLIBRARIAN_ADMIN_PASSWORD}"
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

echo "LazyLibrarian: seeded config.ini with BASIC auth (user '${HTTP_USER}')."
