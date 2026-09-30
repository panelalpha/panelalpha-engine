#!/bin/bash
# Seed this account's secrets once into ~/.panelalpha/yamtrack (survives redeploys;
# ~/project does not) and make sure the .env the compose file lists exists.
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/yamtrack"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${STORE_DIR}/app.env" ]; then
    SECRET="$(openssl rand -hex 40)"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+' | cut -c1-24)"
    (
        umask 077
        printf '# Django signing key; rotating it logs everyone out.\nSECRET=%s\n' "${SECRET}" > "${STORE_DIR}/app.env"
        printf 'YAMTRACK_ADMIN_USER=admin\nYAMTRACK_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${STORE_DIR}/admin.env"
        cat > "${STORE_DIR}/credentials.txt" <<NOTE
Yamtrack on this account

ADMIN LOGIN (created once, on the first deploy)
  URL:      <this account's URL>/accounts/login/
  Username: admin
  Password: ${ADMIN_PASSWORD}

Registration is closed (REGISTRATION=False). To add users, set REGISTRATION=True
in the account env temporarily, or use manage.py shell as Yamtrack's
docs/administration.md describes.

Metadata search (TMDB, MAL, IGDB, ...) uses the upstream's bundled default keys;
set TMDB_API etc. in the account env to use your own.

SQLite and Redis data live on the named volumes 'db' and 'redis_data'.
NOTE
    )
    echo "[yamtrack] secrets written to ${STORE_DIR}" >&2
else
    echo "[yamtrack] reusing the secrets in ${STORE_DIR}" >&2
fi

touch .env
