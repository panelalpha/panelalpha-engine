#!/bin/sh
# One-shot before the web service publishes its port. The image entrypoint has
# already migrated the database; create the admin only while there are no users.
set -e
cd /app
if php bin/console.php user:list | grep -q 'id: '; then
    echo "users exist; not seeding the admin"
    exit 0
fi
php bin/console.php user:create "$MOVARY_ADMIN_EMAIL" "$MOVARY_ADMIN_PASSWORD" "$MOVARY_ADMIN_NAME" 1
