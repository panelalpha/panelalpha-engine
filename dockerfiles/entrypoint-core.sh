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
# supervisord.conf reads it, and refuses to start at all without it.
export QUEUE_WORKERS="${QUEUE_WORKERS:-8}"
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
