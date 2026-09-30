#!/bin/sh
# Runs as the database owner: applies the Prisma migrations, then sets the
# app/worker/monitor/migration login passwords (upstream's provisioning script).
set -e
cd /src
DATABASE_URL="$(cat /run/secrets/owner_database_url)"
TENANT_CONTEXT_SECRET="$(cat /run/secrets/tenant_context_secret)"
TEMPOCOVE_MIGRATION_DB_PASSWORD="$(cat /run/secrets/db_migration_password)"
TEMPOCOVE_APP_DB_PASSWORD="$(cat /run/secrets/db_app_password)"
TEMPOCOVE_WORKER_DB_PASSWORD="$(cat /run/secrets/db_worker_password)"
TEMPOCOVE_MONITOR_DB_PASSWORD="$(cat /run/secrets/db_monitor_password)"
export DATABASE_URL TENANT_CONTEXT_SECRET TEMPOCOVE_MIGRATION_DB_PASSWORD \
       TEMPOCOVE_APP_DB_PASSWORD TEMPOCOVE_WORKER_DB_PASSWORD TEMPOCOVE_MONITOR_DB_PASSWORD
./node_modules/.bin/prisma migrate deploy --schema prisma/postgresql/schema.prisma
node scripts/provision-postgres-logins.mjs
