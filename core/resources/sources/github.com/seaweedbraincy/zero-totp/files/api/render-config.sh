#!/bin/sh
# Renders /api/config/config.yml from the environment, then hands off to the
# image's own entrypoint. The API reads ./config/config.yml at import time and
# the config dir is NOT baked into the image (the e2e stack mounts it), so it
# must be provided at runtime. Secrets and the DB password arrive from the
# environment (persisted by the prepare hook); the domain comes from PUBLIC_URL,
# which the engine rewrites from http://localhost to the account's https origin.
set -e

# Bare host for config.yml `domain`: strip scheme and any :port / path.
HOST=$(printf '%s' "${PUBLIC_URL:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#[:/].*$##')
[ -n "$HOST" ] || HOST="localhost"

mkdir -p /api/config
cat > /api/config/config.yml <<EOF
api:
    private_key_path: "/api/secret/private.pem"
    public_key_path: "/api/secret/public.pem"
    flask_secret_key: "${ZT_FLASK_SECRET_KEY}"
    server_side_encryption_key: "${ZT_SSE_KEY}"
environment:
    type: "production"
    config_version: 1.0
    domain: "${HOST}"
database:
    database_uri: "mysql://${ZT_DB_USER}:${ZT_DB_PASSWORD}@database:3306/${ZT_DB_NAME}"
features:
    emails:
        require_email_validation: false
    signup_enabled: true
EOF

# WORKDIR is /api; auto-upgrade tells start.sh to run `alembic upgrade head`.
exec ./entrypoint.sh auto-upgrade
