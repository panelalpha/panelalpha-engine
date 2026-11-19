#!/bin/sh
# Runs as the image's `mercator` user, after docker/entrypoint.sh has migrated
# the database and before supervisord starts nginx and php-fpm, so the site
# never answers before the admin has the generated login. It runs from the
# mount, not from the image.
cd /var/www/mercator
php /panelalpha/mercator-admin.php \
    || echo "[panelalpha] mercator: the first admin could not be set up; see above" >&2
exec "$@"
