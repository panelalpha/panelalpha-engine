#!/bin/bash
# Generates the Django secret and the database passwords
# once in ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/eonvelope"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/db.env" ]; then
    (umask 077; {
        printf 'MARIADB_DATABASE=email_archive_django\n'
        printf 'MARIADB_USER=eonvelope\n'
        printf 'MARIADB_PASSWORD=%s\n' "$(openssl rand -hex 24)"
        printf 'MARIADB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)"
    } > "${STORE}/db.env")
fi
if [ ! -f "${STORE}/app.env" ]; then
    pass=$(sed -n 's/^MARIADB_PASSWORD=//p' "${STORE}/db.env")
    (umask 077; {
        printf 'DATABASE=email_archive_django\n'
        printf 'DATABASE_USER=eonvelope\n'
        printf 'DATABASE_PASSWORD=%s\n' "${pass}"
        printf 'SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
        # Upstream's docker/docker-compose.yml value; the owner changes it after the first login.
        printf 'DJANGO_SUPERUSER_PASSWORD=rootqwertz123\n'
    } > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env

touch .env
chmod +r panelalpha/*
