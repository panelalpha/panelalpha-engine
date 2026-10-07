#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do:
# persist this account's secrets where the next deploy will not delete them, and
# seed ~/project/.env with the account-tunable defaults the env_vars merge sits
# over. The seeded admin's login is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env, which the init service
# reads as an env_file.
set -e
cd ~/project

say() { echo "[pyfedi] $*" >&2; }

# ~/.panelalpha/pyfedi/ survives; ~/project is emptied on every deploy
# (ProjectTree::clearContents), so a secret written there is regenerated every
# rebuild -- a new SECRET_KEY logs everyone out and invalidates JWTs, and a new
# DB password locks the app out of the pgdata volume that still holds the old
# one. Two env files: the database has no business holding the app secret, and
# compose delivers each as its own env_file.
STORE="${HOME}/.panelalpha/pyfedi"
DB_ENV="${STORE}/db.env"
APP_ENV="${STORE}/app.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    # hex only: read back from an unquoted env file and embedded in the
    # DATABASE_URL DSN, so no '/', '+', '@' or '=' to confuse either.
    PG_PASSWORD="$(openssl rand -hex 24)"
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its FIRST boot to create the role, and by
# nothing else. Written once and never regenerated: the value is baked into the
# pgdata volume, so changing it would lock the app out of its own database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# file strands the pgdata volume (old password) and logs every session out.

# Signs session cookies and the alpha-API JWTs. Rotating it logs everyone out.
SECRET_KEY=${SECRET_KEY}

# PieFed reads the database straight from DATABASE_URL. The psycopg2 scheme is
# required (SQLAlchemy 2.x rejects a bare postgres://); host is the db service.
DATABASE_URL=postgresql+psycopg2://piefed:${PG_PASSWORD}@db:5432/piefed
EOF
    )
    say "secrets written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# ~/project/.env: ProjectEnvironment::apply() merges the account's env_vars over
# it and the app reads it as its first env_file, so a key here is a default the
# panel can override. Nothing secret goes here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}

# SERVER_NAME is overridden at boot from PA_PUBLIC_URL by the app/init wrappers;
# this is only a floor so config.py's os.environ.get('SERVER_NAME').lower()
# never sees None.
write_default SERVER_NAME localhost
write_default CACHE_TYPE RedisCache
write_default CACHE_REDIS_DB 1
write_default CACHE_REDIS_URL redis://redis:6379/0
write_default CELERY_BROKER_URL redis://redis:6379/1
write_default RESULT_BACKEND redis://redis:6379/1
write_default FULL_AP_CONTEXT 0
write_default ENABLE_ALPHA_API true
write_default CORS_ALLOW_ORIGIN '*'
write_default HTTP_PROTOCOL https
say "wrote defaults to ~/project/.env"
