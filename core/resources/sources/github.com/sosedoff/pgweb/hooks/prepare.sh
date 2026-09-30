#!/bin/bash
# Generates the HTTP login and the sidecar DB password ONCE into
# ~/.panelalpha/pgweb (survives redeploys; ~/project does not). Never rewritten:
# the database was initialised with that password, and pgweb.env is where the
# owner may point pgweb at their own database instead.
set -e

say() { echo "[pgweb] $*" >&2; }

STORE="${HOME}/.panelalpha/pgweb"
PG_ENV="${STORE}/postgres.env"
APP_ENV="${STORE}/pgweb.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${PG_ENV}" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    AUTH_PASSWORD="$(openssl rand -hex 16)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "${DB_PASSWORD}" > "${PG_ENV}"
      cat > "${APP_ENV}" <<EOT
# HTTP Basic auth in front of every pgweb route.
PGWEB_AUTH_USER=admin
PGWEB_AUTH_PASS=${AUTH_PASSWORD}
# The ONLY database pgweb opens (it runs with --lock-session). Replace with your
# own postgres:// URL and rebuild to browse another database instead.
PGWEB_DATABASE_URL=postgres://pgweb:${DB_PASSWORD}@postgres:5432/pgweb?sslmode=disable
EOT
      cat > "${NOTE}" <<EOT
pgweb on this account
=====================
Browser login (HTTP Basic auth):
  User:     admin
  Password: ${AUTH_PASSWORD}

pgweb is locked to one database: the bundled PostgreSQL sidecar (database and
user "pgweb", password in postgres.env). To browse your own PostgreSQL instead,
edit PGWEB_DATABASE_URL in pgweb.env and rebuild the project.
EOT
    )
    say "generated login and database password -> ${STORE}"
fi
chmod 600 "${PG_ENV}" "${APP_ENV}" "${NOTE}"

docker pull sosedoff/pgweb:0.17.0 >/dev/null 2>&1 || true
docker pull postgres:18-alpine >/dev/null 2>&1 || true
say "prepare complete"
