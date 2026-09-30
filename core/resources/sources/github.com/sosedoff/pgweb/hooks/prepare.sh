#!/bin/bash
# Generates the sidecar DB password ONCE into ~/.panelalpha/pgweb (survives
# redeploys; ~/project does not). The HTTP login is the engine's
# (`credentials:`, ~/.panelalpha/app-credentials.env). Never rewritten:
# the database was initialised with that password, and pgweb.env is where the
# owner may point pgweb at their own database instead.
set -e

say() { echo "[pgweb] $*" >&2; }

STORE="${HOME}/.panelalpha/pgweb"
PG_ENV="${STORE}/postgres.env"
APP_ENV="${STORE}/pgweb.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${PG_ENV}" ]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "${DB_PASSWORD}" > "${PG_ENV}"
      cat > "${APP_ENV}" <<EOT
# The ONLY database pgweb opens (it runs with --lock-session). Replace with your
# own postgres:// URL and rebuild to browse another database instead.
PGWEB_DATABASE_URL=postgres://pgweb:${DB_PASSWORD}@postgres:5432/pgweb?sslmode=disable
EOT
    )
    say "generated the database password -> ${STORE}"
fi
chmod 600 "${PG_ENV}" "${APP_ENV}"

docker pull sosedoff/pgweb:0.17.0 >/dev/null 2>&1 || true
docker pull postgres:18-alpine >/dev/null 2>&1 || true
say "prepare complete"
