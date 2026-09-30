#!/bin/bash
set -e
cd ~/project

# Everything Miniflux and its database need that the checkout cannot carry. The
# generated app service reads ~/project/.env through `env_file:`, and compose
# interpolates the database service from it.
#
# The database password is generated once into ~/.panelalpha/miniflux: a
# redeploy empties ~/project, and the postgres volume keeps the first password.
STORE_DIR="${HOME}/.panelalpha/miniflux"
DB_ENV="${STORE_DIR}/db.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${DB_ENV}" ]; then
    (
        umask 077
        echo "DB_PASSWORD=$(openssl rand -hex 16)" > "${DB_ENV}"
    )
    echo "[miniflux] database password written to ${STORE_DIR}" >&2
fi
. "${DB_ENV}"

# DATABASE_URL has no usable default: config/options.go ships a developer's
# `user=postgres password=postgres dbname=miniflux2`, and cli.go exits on
# NewConnectionPool and again on store.Ping() when it cannot connect.
# `database` is the service name docker-compose.override.yml gives the
# PostgreSQL beside it; hex password, so nothing in it needs URL-escaping.
#
# RUN_MIGRATIONS: the binary creates its own schema. Without it the first
# boot against an empty database stops at database.IsSchemaUpToDate().
#
# CREATE_ADMIN: Miniflux has no sign-up page and no first-run wizard.
# ADMIN_USERNAME / ADMIN_PASSWORD are the engine's (`credentials:`), read by the
# app from ~/.panelalpha/app-credentials.env (the override's env_file).
# createAdminUser() skips a username that already exists, so a redeploy resets
# nobody.
#
# The POSTGRES_* three are read by the database service through compose
# interpolation of this same file. Miniflux ignores configuration keys it
# does not know (parser.go parseLine), so sharing one file is safe.
cat > .env <<EOF
DATABASE_URL=postgres://miniflux:${DB_PASSWORD}@database:5432/miniflux?sslmode=disable
RUN_MIGRATIONS=1
CREATE_ADMIN=1
POSTGRES_DB=miniflux
POSTGRES_USER=miniflux
POSTGRES_PASSWORD=${DB_PASSWORD}
EOF
chmod 600 .env

# Fetch the two images the override adds now rather than during the build.
# `docker compose up -d` pulls what it lacks through the account's nested
# daemon while the app is starting, and neither image is in the host cache the
# engine seeds from: that cache is filled from the *generated* compose file,
# which knows nothing about services added by an override. Best effort --
# compose pulls them the slow way if this fails.
docker pull postgres:17-alpine >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
