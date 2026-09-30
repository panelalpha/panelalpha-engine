#!/bin/bash
# Generates the Mongo password and the JWT/NextAuth secrets once into
# ~/.panelalpha (a redeploy empties ~/project).
set -e
STORE_DIR="${HOME}/.panelalpha/textbee"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/api.env" ]; then
    PW="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'MONGO_INITDB_ROOT_USERNAME=textbee\nMONGO_INITDB_ROOT_PASSWORD=%s\n' "$PW" > "${STORE_DIR}/db.env"
        # Turnstile: upstream's .env.example test secret (always passes); a project env var overrides it.
        printf 'MONGO_URI=mongodb://textbee:%s@textbee-db:27017/textbee?authSource=admin\nJWT_SECRET=%s\nEMAIL_LINK_SECRET=%s\nCLOUDFLARE_TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA\n' \
            "$PW" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "${STORE_DIR}/api.env"
        printf 'NEXTAUTH_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE_DIR}/web.env"
    )
    echo "[textbee] secrets written to ${STORE_DIR}" >&2
else
    echo "[textbee] reusing secrets in ${STORE_DIR}" >&2
fi
