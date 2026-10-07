#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: put this account's secrets somewhere the next deploy will not delete,
# and make sure the .env the compose references exists.
set -e
cd ~/project

say() { echo "[bugsink] $*" >&2; }

# ~/.panelalpha/bugsink/ survives a redeploy; ~/project is emptied every deploy.
# A secret written under ~/project would be regenerated on every
# rebuild -- a new SECRET_KEY logs everyone out, and a new admin password would
# not even take effect (CREATE_SUPERUSER only runs when the DB has no users, and
# the DB lives on a named volume that outlives the deploy).
STORE_DIR="${HOME}/.panelalpha/bugsink"
APP_ENV="${STORE_DIR}/app.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ]; then
    # SECRET_KEY: Django signing key, required with DEBUG off and checked by
    # `bugsink-manage check --deploy` at boot; must be long and must not carry
    # the `django-insecure-` prefix. base64 of 50 bytes is ~66 chars.
    SECRET_KEY="$(openssl rand -base64 50 | tr -d '\n')"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once on the first deploy and never regenerated. Deleting this file
# logs everyone out (new SECRET_KEY) while the admin and all data stay in the
# SQLite volume. Reused on every redeploy.

# Django signing key; required with DEBUG off.
SECRET_KEY=${SECRET_KEY}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# The admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env. The image wants it as one email:password
# value, applied by prestart only when the database has no users.
set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
(
    umask 077
    sed -i '/^CREATE_SUPERUSER=/d' "${APP_ENV}"
    printf 'CREATE_SUPERUSER=%s:%s\n' "${BUGSINK_ADMIN_EMAIL}" "${BUGSINK_ADMIN_PASSWORD}" >> "${APP_ENV}"
)

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. The account's env_vars are merged into it by the platform.
touch .env
say "prepare complete"
