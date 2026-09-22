#!/bin/bash
set -e
cd ~/project

# Everything Miniflux and its database need that the checkout cannot carry. The
# repository ships no .env, and the generated app service reads one through
# `env_file:`, so this file is both the credential store and the way in.
#
# Written once: the postgres volume outlives the checkout, so a redeploy that
# rolled the password would leave the app unable to open its own database, and
# a rolled admin password would be one nobody was ever told.
if [ ! -f .env ]; then
    # openssl is present in the account image; the od fallback is coreutils,
    # which is the smaller assumption if it ever is not.
    DB_PASSWORD=$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
    ADMIN_PASSWORD=$(openssl rand -hex 12 2>/dev/null || head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')

    # DATABASE_URL has no usable default: config/options.go ships a developer's
    # `user=postgres password=postgres dbname=miniflux2`, and cli.go exits on
    # NewConnectionPool and again on store.Ping() when it cannot connect.
    # `database` is the service name docker-compose.override.yml gives the
    # PostgreSQL beside it; hex password, so nothing in it needs URL-escaping.
    #
    # RUN_MIGRATIONS: the binary creates its own schema. Without it the first
    # boot against an empty database stops at database.IsSchemaUpToDate().
    #
    # CREATE_ADMIN: Miniflux has no sign-up page and no first-run wizard, so
    # without this there is no way into a fresh instance except the interactive
    # `miniflux -create-admin`. createAdminUser() skips a username that already
    # exists, so this stays true on every later boot without resetting anyone.
    #
    # The POSTGRES_* three are read by the database service through compose
    # interpolation of this same file. Miniflux ignores configuration keys it
    # does not know (parser.go parseLine), so sharing one file is safe.
    cat > .env <<EOF
DATABASE_URL=postgres://miniflux:${DB_PASSWORD}@database:5432/miniflux?sslmode=disable
RUN_MIGRATIONS=1
CREATE_ADMIN=1
ADMIN_USERNAME=admin
ADMIN_PASSWORD=${ADMIN_PASSWORD}
POSTGRES_DB=miniflux
POSTGRES_USER=miniflux
POSTGRES_PASSWORD=${DB_PASSWORD}
EOF
    chmod 600 .env

    # The credential a person has to be handed. Every Miniflux compose file
    # published upstream -- the three in contrib/docker-compose and the
    # devcontainer -- installs the admin `admin` / `test123`, which is a known
    # password on a public URL.
    cat > .panelalpha-admin-password <<EOF
username: admin
password: ${ADMIN_PASSWORD}
EOF
    chmod 600 .panelalpha-admin-password
fi

# Fetch the two images the override adds now rather than during the build.
# `docker compose up -d` pulls what it lacks through the account's nested
# daemon while the app is starting, and neither image is in the host cache the
# engine seeds from: that cache is filled from the *generated* compose file,
# which knows nothing about services added by an override. Best effort --
# compose pulls them the slow way if this fails.
docker pull postgres:17-alpine >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
