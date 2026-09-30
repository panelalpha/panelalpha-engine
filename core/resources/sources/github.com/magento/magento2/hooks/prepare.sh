#!/bin/bash
set -e
cd ~/project

# ~/project is /app in the container and is emptied before every deploy, so
# everything Magento must keep lives in ~/.panelalpha/magento instead, which
# the compose override mounts at /pa-data:
#
#   admin.env    the admin credentials below, generated once
#   etc/         app/etc/env.php (crypt key, DB, install date) and config.php,
#                reached through symlinks written here on every deploy
#   media/       pub/media, bind-mounted over the checkout's copy
#
# Without the env.php link a redeploy lands in the upgrade stage with no
# env.php, which exits 0 and leaves an uninstalled store over a full database.
STORE="${HOME}/.panelalpha/magento"
mkdir -p "${STORE}/etc" "${STORE}/media"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# Magento's installer wants an admin username, password and email, and there
# is nowhere for a customer to type them. The admin path is generated too: left
# alone Magento invents one and prints it once, into a build log nobody keeps.
# Generated once and copied into .env (which the generated compose file loads)
# on every deploy; the store already holds them, so a new set would only be
# a record of credentials that do not work.
if [ ! -s "${STORE}/admin.env" ]; then
    (
        umask 077
        # An older deploy's values, when this checkout was not wiped.
        if grep -q '^MAGENTO_ADMIN_PASSWORD=.' .env 2>/dev/null; then
            grep '^MAGENTO_ADMIN_' .env > "${STORE}/admin.env"
        else
            # Magento requires a password with both letters and digits, at least 7 long.
            printf 'MAGENTO_ADMIN_USER=admin\nMAGENTO_ADMIN_EMAIL=admin@example.com\nMAGENTO_ADMIN_PASSWORD=%s\nMAGENTO_ADMIN_URI=%s\n' \
                "$(openssl rand -hex 12)Aa1" "admin_$(openssl rand -hex 4)" > "${STORE}/admin.env"
        fi
    )
fi
chmod 600 "${STORE}/admin.env"
touch .env
sed -i '/^MAGENTO_ADMIN_/d' .env
cat "${STORE}/admin.env" >> .env

# An install from before the store existed, on a rebuild that did not wipe.
for f in env.php config.php; do
    if [ -f "app/etc/${f}" ] && [ ! -L "app/etc/${f}" ] && [ ! -s "${STORE}/etc/${f}" ]; then
        cp -p "app/etc/${f}" "${STORE}/etc/${f}"
    fi
done
# Dangling until setup:install writes through them on the first deploy. The
# target is the container's path: Magento resolves no symlinks when it checks a
# config path, and fopen() follows this one.
ln -sfn /pa-data/etc/env.php app/etc/env.php
ln -sfn /pa-data/etc/config.php app/etc/config.php

# The checkout's pub/media carries the .htaccess files that fence off
# customer/, downloadable/ and import/. Seeded once; uploads land here after.
if [ -z "$(ls -A "${STORE}/media")" ]; then
    cp -a pub/media/. "${STORE}/media/"
fi
