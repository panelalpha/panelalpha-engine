#!/bin/bash
# Database password generated once into ~/.panelalpha: ~/project is wiped on
# every deploy and a new password would lock Drop out of its database volume.
set -e
STORE="${HOME}/.panelalpha/drop"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/db.env" ]; then
    PW=$(openssl rand -hex 24)
    (umask 077
     printf 'POSTGRES_USER=drop\nPOSTGRES_PASSWORD=%s\nPOSTGRES_DB=drop\n' "${PW}" > "${STORE}/db.env"
     printf 'DATABASE_URL=postgres://drop:%s@postgres:5432/drop\n' "${PW}" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/app.env"
