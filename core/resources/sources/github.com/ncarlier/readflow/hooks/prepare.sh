#!/bin/bash
# Account shell, after the clone and after overrides/ and files/ are in place,
# before `docker compose up`. Two jobs the compose file cannot do for itself:
# persist this account's secrets where the next deploy will not delete them, and
# materialise the container env + htpasswd from them on every deploy.
set -e
cd ~/project

say() { echo "[readflow] $*" >&2; }

# ~/project is emptied on every deploy (ProjectTree::clearContents), so a secret
# written there is regenerated every rebuild -- a new DB password locks the app
# out of the pgdata volume that still holds the old one, and a new hash key/salt
# breaks readflow's content hashing. ~/.panelalpha/readflow/ is the only writable
# place a redeploy does not delete.
STORE="${HOME}/.panelalpha/readflow"
SECRETS="${STORE}/secrets.env"   # generated once, sourced every deploy
DB_ENV="${STORE}/db.env"         # POSTGRES_PASSWORD, read by the db container
APP_ENV="${STORE}/app.env"       # READFLOW_* secrets, injected into readflow
HTPASSWD="${STORE}/htpasswd"     # basic-auth credentials file

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRETS}" ]; then
    # hex only: embedded in a DSN and read back from env files, so no
    # '/', '+', '@', '=' or quotes to confuse either. The htpasswd hashers
    # (bcrypt / SHA) accept it as-is.
    PG_PASSWORD="$(openssl rand -hex 24)"
    HASH_KEY="$(openssl rand -hex 32)"
    HASH_SALT="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${SECRETS}" <<EOF
# Written once by PanelAlpha on the first deploy and never regenerated. Deleting
# this file strands the pgdata volume (old password) and breaks content hashing.
PG_PASSWORD=${PG_PASSWORD}
HASH_KEY=${HASH_KEY}
HASH_SALT=${HASH_SALT}
EOF
    )
    say "secrets written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# shellcheck disable=SC1090
. "${SECRETS}"
# BASIC_USER / BASIC_PASSWORD: the engine's (`credentials:`), written before
# this hook. Read after secrets.env, which held them on older accounts.
set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a

# Materialise the per-deploy files from the persisted secrets (umask so nothing
# is group/other readable except where a container needs it).
umask 077

# 1. Postgres password, read by the db container on its first boot.
cat > "${DB_ENV}" <<EOF
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF

# 2. readflow secrets. The DSN points at the `db` service; hash key/salt are
#    hex-encoded (config.HexString). sslmode=disable: traffic stays on the
#    compose network.
cat > "${APP_ENV}" <<EOF
READFLOW_DATABASE_URI=postgres://readflow:${PG_PASSWORD}@db/readflow?sslmode=disable
READFLOW_HASH_SECRET_KEY=${HASH_KEY}
READFLOW_HASH_SECRET_SALT=${HASH_SALT}
EOF

# 3. htpasswd. Prefer a bcrypt hash (htpasswd -nbB) when apache2-utils is on the
#    host; otherwise fall back to {SHA} (base64(sha1)), which readflow's htpasswd
#    parser also accepts. The password is the engine's 24 random letters and
#    digits, so it is not brute-forceable whichever digest wraps it.
if command -v htpasswd >/dev/null 2>&1; then
    htpasswd -nbB "${BASIC_USER}" "${BASIC_PASSWORD}" > "${HTPASSWD}"
    say "htpasswd written (bcrypt)"
else
    SHA="$(printf '%s' "${BASIC_PASSWORD}" | openssl dgst -sha1 -binary | openssl base64)"
    printf '%s:{SHA}%s\n' "${BASIC_USER}" "${SHA}" > "${HTPASSWD}"
    say "htpasswd written ({SHA})"
fi
# The file is bind-mounted read-only into the readflow container, which runs as
# the image's own non-root user (uid 65532), a different uid than the account
# owner; 600 would be unreadable there. 644 keeps it inside this single-tenant
# account's own container. It holds only a hash, not the plaintext password.
chmod 644 "${HTPASSWD}"

# The engine lists ~/project/.env as an env_file on generated services; make
# sure it exists even when the platform has not written it yet, so
# `docker compose up` does not abort.
touch .env
say "prepare complete"
