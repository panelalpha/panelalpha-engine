#!/bin/bash
# Generates the database password and the JWT/cookie secrets once into
# ~/.panelalpha/tracearr/ (~/project is wiped on every deploy; the database
# volume keeps the password it was created with).
set -e
STORE="${HOME}/.panelalpha/tracearr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ]; then
    DB_PW="$(openssl rand -hex 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${DB_PW}" > "${STORE}/db.env"
        printf 'DATABASE_URL=postgres://tracearr:%s@timescale:5432/tracearr\nJWT_SECRET=%s\nCOOKIE_SECRET=%s\n' \
            "${DB_PW}" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "${STORE}/app.env"
    )
    echo "[panelalpha] tracearr: secrets written to ${STORE}" >&2
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
