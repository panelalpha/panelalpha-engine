#!/bin/bash
# The login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs. Here the secrets the
# repository's docker/docker-compose.yml publishes are generated once, in
# ~/.panelalpha (survives redeploys; ~/project does not):
#
# - JWT_SECRET (upstream: jwtsecretjwtsecretjwtsecret) signs the admin's
#   access and refresh tokens; with the published one anyone could mint a
#   super user's token without a password.
# - the MySQL password (upstream: userfeedback). It is baked into the mysql
#   volume on its first start, so it must never change afterwards.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/abc-user-feedback"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# One file can be gone while the other and the volume remain: the password
# either one still holds is the volume's, so it is never generated again then.
DB_PASSWORD=""
if [ -s "${STORE}/db.env" ]; then
    DB_PASSWORD="$(sed -n 's/^MYSQL_PASSWORD=//p' "${STORE}/db.env")"
fi
if [ -z "${DB_PASSWORD}" ] && [ -s "${STORE}/api.env" ]; then
    DB_PASSWORD="$(sed -n 's#^MYSQL_PRIMARY_URL=mysql://userfeedback:\([^@]*\)@.*#\1#p' "${STORE}/api.env")"
fi
if [ -z "${DB_PASSWORD}" ]; then
    # Hex: no character a URL or an env file could misread.
    DB_PASSWORD="$(openssl rand -hex 24)"
fi
if [ ! -s "${STORE}/db.env" ]; then
    (umask 077; printf 'MYSQL_PASSWORD=%s\n' "${DB_PASSWORD}" > "${STORE}/db.env")
fi
if [ ! -s "${STORE}/api.env" ]; then
    (umask 077
     printf 'JWT_SECRET=%s\nMYSQL_PRIMARY_URL=mysql://userfeedback:%s@mysql:3306/userfeedback\n' \
         "$(openssl rand -hex 32)" "${DB_PASSWORD}" > "${STORE}/api.env")
fi
chmod 600 "${STORE}/db.env" "${STORE}/api.env"

# The proxy's config and the setup script are read by the images' own users.
chmod -R a+rX panelalpha/abc-user-feedback
