#!/bin/bash
# Generates the metadata-DB password, the encryption key and the admin password
# ONCE into ~/.panelalpha/pgbackweb (survives redeploys; ~/project does not).
# Regenerating the encryption key would make every stored connection string
# unreadable, so these files are never rewritten.
set -e

say() { echo "[pgbackweb] $*" >&2; }

STORE_DIR="${HOME}/.panelalpha/pgbackweb"
SECRETS="${STORE_DIR}/secrets.env"
ADMIN="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${SECRETS}" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    ( umask 077
      cat > "${SECRETS}" <<EOT
# Written once on the first deploy. Do not change: the metadata database was
# initialised with this password and stored credentials are encrypted with the key.
POSTGRES_PASSWORD=${DB_PASSWORD}
PBW_POSTGRES_CONN_STRING=postgresql://pgbackweb:${DB_PASSWORD}@postgres:5432/pgbackweb?sslmode=disable
PBW_ENCRYPTION_KEY=$(openssl rand -hex 32)
EOT
    )
    say "generated database password and encryption key -> ${SECRETS}"
fi

if [ ! -f "${ADMIN}" ]; then
    # hex, 40 chars: inside the app's 6-50 limit and safe in a form body
    ADMIN_PASSWORD="$(openssl rand -hex 20)"
    ( umask 077
      cat > "${ADMIN}" <<EOT
PBW_ADMIN_NAME=Administrator
PBW_ADMIN_EMAIL=admin@pgbackweb.local
PBW_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOT
      cat > "${NOTE}" <<EOT
PG Back Web on this account
===========================
Login (seeded automatically before the site goes public):
  Email:    admin@pgbackweb.local
  Password: ${ADMIN_PASSWORD}

Change it from the app's profile page. A redeploy does not reset it; the
bootstrap only creates the user while the metadata database has none.
Local backups are kept on the named volume mounted at /backups.
EOT
    )
    say "generated admin password -> ${ADMIN}; notes in ${NOTE}"
fi
chmod 600 "${SECRETS}" "${ADMIN}" "${NOTE}"

docker pull eduardolat/pgbackweb:0.5.2 >/dev/null 2>&1 || true
docker pull postgres:17-alpine >/dev/null 2>&1 || true
docker pull curlimages/curl:8.11.1 >/dev/null 2>&1 || true
say "prepare complete"
