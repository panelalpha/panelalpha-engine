#!/bin/bash
# Generates the database and admin passwords once into ~/.panelalpha/teedy/
# (~/project is wiped every deploy) and hands Teedy the admin's bcrypt hash.
set -e
cd ~/project

say() { echo "[panelalpha] teedy: $*" >&2; }

STORE="${HOME}/.panelalpha/teedy"
DB_ENV="${STORE}/db.env"
APP_ENV="${STORE}/app.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    PG_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20)"
    # Teedy verifies $2y$ hashes (at.favre bcrypt); php is in the account image.
    ADMIN_HASH="$(ADMIN_PASSWORD="${ADMIN_PASSWORD}" php -r 'echo password_hash(getenv("ADMIN_PASSWORD"), PASSWORD_BCRYPT);')"
    case "${ADMIN_HASH}" in '$2y$'*) ;; *) say "bcrypt hashing failed"; exit 1 ;; esac
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
        # Single quotes: compose must not interpolate the '$' in the hash.
        cat > "${APP_ENV}" <<ENV_EOF
DATABASE_PASSWORD=${PG_PASSWORD}
DOCS_ADMIN_PASSWORD_INIT='${ADMIN_HASH}'
ENV_EOF
        cat > "${NOTE}" <<NOTE_EOF
Teedy on this account
=====================

  URL:      <this account's URL>/
  Username: admin
  Password: ${ADMIN_PASSWORD}

Teedy seeds admin/admin; DOCS_ADMIN_PASSWORD_INIT (the bcrypt hash of the
password above) replaces it when the webapp starts, before Jetty opens its
port. It only replaces the upstream default, so a password changed under
Settings > Account survives redeploys. Documents and files live on the
teedy-data volume, metadata in PostgreSQL (pgdata volume).
NOTE_EOF
    )
    say "credentials written to ${NOTE}"
else
    say "reusing the secrets in ${STORE}"
fi
chmod 600 "${DB_ENV}" "${APP_ENV}" "${NOTE}"
