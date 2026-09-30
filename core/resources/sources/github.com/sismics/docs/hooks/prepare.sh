#!/bin/bash
# Generates the database password once into ~/.panelalpha/teedy/ (~/project is
# wiped every deploy) and hands Teedy the bcrypt hash of the admin password the
# engine generated (`credentials:`, ~/.panelalpha/app-credentials.env).
set -e
cd ~/project

say() { echo "[panelalpha] teedy: $*" >&2; }

STORE="${HOME}/.panelalpha/teedy"
DB_ENV="${STORE}/db.env"
APP_ENV="${STORE}/app.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    PG_PASSWORD="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9')"
    set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
    # Teedy verifies $2y$ hashes (at.favre bcrypt); php is in the account image.
    ADMIN_HASH="$(ADMIN_PASSWORD="${TEEDY_ADMIN_PASSWORD}" php -r 'echo password_hash(getenv("ADMIN_PASSWORD"), PASSWORD_BCRYPT);')"
    case "${ADMIN_HASH}" in '$2y$'*) ;; *) say "bcrypt hashing failed"; exit 1 ;; esac
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${DB_ENV}"
        # Single quotes: compose must not interpolate the '$' in the hash.
        cat > "${APP_ENV}" <<ENV_EOF
DATABASE_PASSWORD=${PG_PASSWORD}
DOCS_ADMIN_PASSWORD_INIT='${ADMIN_HASH}'
ENV_EOF
    )
    say "secrets written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi
chmod 600 "${DB_ENV}" "${APP_ENV}"
