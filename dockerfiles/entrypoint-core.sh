#!/bin/bash
set -e
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage
mkdir -p /var/tmp/panelalpha-backup
chown www-data:www-data /var/tmp/panelalpha-backup
chmod 1777 /var/tmp/panelalpha-backup
# supervisord.conf reads it, and refuses to start at all without it.
export QUEUE_WORKERS="${QUEUE_WORKERS:-8}"
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
