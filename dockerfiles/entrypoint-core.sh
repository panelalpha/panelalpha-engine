#!/bin/bash
set -e
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/logs
# core.sqlite lives on core-storage, shared with the metrics container via
# volumes_from; php artisan migrate --force in the deploy path expects the
# file to already exist. Skipped on a host int-updater.sh kept on MySQL
# (CORE_DB_CONNECTION=mysql) -- it has no use for this file.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p /var/www/html/storage/database
    touch /var/www/html/storage/database/core.sqlite
fi
chown -R www-data:www-data /var/www/html/storage
mkdir -p /var/tmp/panelalpha-backup
chown www-data:www-data /var/tmp/panelalpha-backup
chmod 1777 /var/tmp/panelalpha-backup
# Writes /etc/supervisor/conf.d/queue.generated.conf with the worker count
# baked in as a literal; supervisord.conf [include]s it. See
# App\Support\QueueWorkers: a plain %(ENV_QUEUE_WORKERS)s could not be changed
# live, since Docker freezes a running container's environment -- a generated
# file supervisorctl reread/update can pick up without one.
php /var/www/html/artisan system:queue-workers:sync
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
