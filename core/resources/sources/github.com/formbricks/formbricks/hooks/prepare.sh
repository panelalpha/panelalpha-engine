#!/bin/bash
# Generate every secret once into ~/.panelalpha (~/project is re-cloned on every
# deploy; the database volume and encrypted data keep them). One env file per
# consumer, so each service sees only what it needs.
set -e
DATA="${HOME}/.panelalpha/formbricks"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${DATA}/app.env" ]; then
    umask 077
    rnd() { openssl rand -hex 32; }
    PG=$(rnd); AUTHZ_DB=$(rnd); AUTHZ_TOKEN=$(rnd); HUB_KEY=$(rnd); CUBE=$(rnd)
    printf 'POSTGRES_PASSWORD=%s\n' "${PG}" > "${DATA}/db.env"
    cat > "${DATA}/app.env" <<ENVEOF
DATABASE_URL=postgresql://postgres:${PG}@postgres:5432/formbricks?schema=public
NEXTAUTH_SECRET=$(rnd)
ENCRYPTION_KEY=$(rnd)
CRON_SECRET=$(rnd)
HUB_API_KEY=${HUB_KEY}
CUBEJS_API_SECRET=${CUBE}
AUTHZED_TOKEN=${AUTHZ_TOKEN}
ENVEOF
    cat > "${DATA}/authzed.env" <<ENVEOF
POSTGRES_ADMIN_URL=postgresql://postgres:${PG}@postgres:5432/postgres?sslmode=disable
AUTHZED_DATABASE_PASSWORD=${AUTHZ_DB}
SPICEDB_DATASTORE_CONN_URI=postgresql://spicedb:${AUTHZ_DB}@postgres:5432/spicedb?sslmode=disable
SPICEDB_GRPC_PRESHARED_KEY=${AUTHZ_TOKEN}
ENVEOF
    cat > "${DATA}/hub.env" <<ENVEOF
DATABASE_URL=postgresql://postgres:${PG}@postgres:5432/formbricks?sslmode=disable
API_KEY=${HUB_KEY}
ENVEOF
    cat > "${DATA}/cube.env" <<ENVEOF
CUBEJS_DB_PASS=${PG}
CUBEJS_API_SECRET=${CUBE}
ENVEOF
    echo "[panelalpha] formbricks: generated secrets in ${DATA}"
fi
chmod 600 "${DATA}"/*.env
touch ~/project/.env
