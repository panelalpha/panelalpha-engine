#!/bin/sh
# One-shot: bring the schema up and seed the administrator BEFORE the app
# container boots. The app image's start.sh creates admin@example.com/changeme
# if that e-mail is missing; by seeding it here with a generated password we
# occupy the e-mail so the insecure default is never created. Must exit 0 even
# when the admin already exists (a redeploy), or the deploy gate fails it.
set -e

# Build the container .env from the environment compose passed in.
/var/www/startup/create_env.sh
cd /var/www/html

export MYSQL_PWD="${DB_PASSWORD}"
/usr/local/bin/wait-for-it.sh "${DB_HOST}:${DB_PORT}" -t 120

php artisan migrate --force

EXISTS=$(mysql -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USERNAME}" -D "${DB_DATABASE}" -N -B \
    -e "SELECT EXISTS(SELECT 1 FROM users WHERE email='${ADMIN_EMAIL}');" 2>/dev/null || echo 0)

if [ "${EXISTS}" != "1" ]; then
    # mixpost-auth:create prompts for name, e-mail, password on stdin.
    printf '%s\n%s\n%s\n' "Admin" "${ADMIN_EMAIL}" "${ADMIN_PASSWORD}" | php artisan mixpost-auth:create
    echo "Seeded Mixpost administrator ${ADMIN_EMAIL}"
else
    echo "Mixpost administrator ${ADMIN_EMAIL} already present; leaving it unchanged"
fi
