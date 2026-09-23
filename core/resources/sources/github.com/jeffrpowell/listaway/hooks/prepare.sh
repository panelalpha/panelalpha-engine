#!/bin/bash
set -e
cd ~/project

# Secrets are generated ONCE and reused on every redeploy. ~/project is wiped
# and re-cloned each deploy, so they live in ~/.panelalpha/listaway/ (the only
# writable, rebuild-surviving directory). Regenerating LISTAWAY_AUTH_KEY would
# invalidate every session cookie; regenerating POSTGRES_PASSWORD would lock the
# app out of the existing pgdata volume — so write only when missing.
SECRET_DIR="$HOME/.panelalpha/listaway"
SECRET_FILE="$SECRET_DIR/secrets.env"
mkdir -p "$SECRET_DIR"
chmod 700 "$HOME/.panelalpha" "$SECRET_DIR" 2>/dev/null || true

if [ ! -f "$SECRET_FILE" ]; then
    # 16 random bytes -> 32 hex chars. The DB password stays alphanumeric so it
    # needs no escaping. LISTAWAY_AUTH_KEY must be exactly 16/24/32 bytes for the
    # AES cookie store (constants.go); 32 hex chars is a valid 32-byte key.
    POSTGRES_PASSWORD=$(openssl rand -hex 16)
    LISTAWAY_AUTH_KEY=$(openssl rand -hex 16)
    ( umask 077
      cat > "$SECRET_FILE" <<EOF
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
LISTAWAY_AUTH_KEY=${LISTAWAY_AUTH_KEY}
EOF
    )
    chmod 600 "$SECRET_FILE"
fi

# Postgres first-init schema. Listaway's embedded init.sql runs
# `CREATE TABLE IF NOT EXISTS listaway.<t>` but never creates the `listaway`
# schema, and the binary log.Fatal's if it is missing (the README has the
# operator run this CREATE SCHEMA by hand). The postgres image runs *.sql from
# /docker-entrypoint-initdb.d once, on an empty data dir, before it accepts TCP
# connections — so the schema exists before either the healthcheck passes or the
# app's own init.sql runs. Not a secret; lives in ~/project and is world-readable
# so the postgres container can read it. Rewritten every deploy (idempotent).
mkdir -p pa-initdb
cat > pa-initdb/01-schema.sql <<'EOF'
CREATE SCHEMA IF NOT EXISTS listaway AUTHORIZATION listaway;
EOF
chmod 755 pa-initdb
chmod 644 pa-initdb/01-schema.sql
