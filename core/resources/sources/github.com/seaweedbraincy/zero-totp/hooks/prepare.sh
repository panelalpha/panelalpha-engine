#!/bin/bash
set -e
cd ~/project

# Zero-TOTP's secrets must be stable across redeploys: the flask session key
# and the server-side encryption key, if regenerated, log everyone out and make
# server-encrypted columns unreadable; a new DB password locks the app out of
# its MariaDB volume. ~/project is wiped every deploy, so they are generated
# once and kept in ~/.panelalpha/zero-totp — the only writable dir that
# survives a rebuild — and reused on every later deploy.
STORE="${HOME}/.panelalpha/zero-totp"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}" 2>/dev/null || true
SECRETS="${STORE}/secrets.env"

if [ ! -f "${SECRETS}" ]; then
    umask 077
    {
        echo "ZT_DB_NAME=zero_totp"
        echo "ZT_DB_USER=api"
        echo "ZT_DB_PASSWORD=$(openssl rand -hex 24)"
        echo "ZT_DB_ROOT_PASSWORD=$(openssl rand -hex 24)"
        # flask_secret_key must be >= 64 chars; both are 128 hex chars.
        echo "ZT_FLASK_SECRET_KEY=$(openssl rand -hex 64)"
        echo "ZT_SSE_KEY=$(openssl rand -hex 64)"
    } > "${SECRETS}"
    chmod 600 "${SECRETS}"
fi

# Compose interpolates ${ZT_*} from ~/project/.env. Rewritten fresh each deploy
# from the persisted store, so the values never drift.
cp "${SECRETS}" .env
chmod 600 .env
