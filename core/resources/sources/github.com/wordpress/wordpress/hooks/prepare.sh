#!/bin/bash
set -e

# Secrets live in ~/.panelalpha/wordpress, written once: ~/project is emptied on
# every deploy, while the MySQL volume keeps the passwords it was created with
# and new keys/salts would sign every user out. Nothing goes into ~/project:
# it is the document root, so a .env there is served to anyone who asks.
STORE="${HOME}/.panelalpha/wordpress"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
(
    umask 077
    if [ ! -s "${STORE}/db.env" ]; then
        printf 'MYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' \
            "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env"
    fi
    if [ ! -s "${STORE}/wordpress.env" ]; then
        DB_PASSWORD=$(sed -n 's/^MYSQL_PASSWORD=//p' "${STORE}/db.env")
        {
            printf 'WORDPRESS_DB_PASSWORD=%s\n' "${DB_PASSWORD}"
            for KEY in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY \
                AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do
                printf 'WORDPRESS_%s=%s\n' "${KEY}" "$(openssl rand -hex 32)"
            done
        } > "${STORE}/wordpress.env"
    fi
)
chmod 600 "${STORE}/db.env" "${STORE}/wordpress.env"
