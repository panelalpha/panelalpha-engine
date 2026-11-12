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
# Statistics config and data are written by php-fpm and the workers, which run
# as www-data; the install leaves both directories owned by root, and a
# from-source install does not create them at all.
if [ -d /opt/panelalpha/shared-hosting ]; then
    for d in /opt/panelalpha/shared-hosting/awstats-config /opt/panelalpha/shared-hosting/awstats-data; do
        mkdir -p "$d"
        chown -R www-data:www-data "$d"
    done
fi
# Coverage recordings: bind-mounted from the host's tests/coverage, which Docker
# creates root-owned when it is missing; php-fpm and the workers write to it as www-data.
mkdir -p /var/www/tests/coverage
chown www-data:www-data /var/www/tests/coverage
# Engine-only directories. Not -R: what root containers write below stays theirs.
mkdir -p /var/lib/panelalpha/archive-stage
chown www-data:www-data /var/lib/panelalpha/archive-stage
# Files core hands the root broker; accounts may only traverse it.
mkdir -p /var/lib/panelalpha/exchange
chown www-data:www-data /var/lib/panelalpha/exchange
chmod 0711 /var/lib/panelalpha/exchange
find /var/lib/panelalpha/exchange -mindepth 1 -mmin +1440 -delete 2>/dev/null || true
if [ -d /opt/panelalpha/shared-hosting ]; then
    for d in /opt/panelalpha/shared-hosting/data/build-cache /opt/panelalpha/shared-hosting/data/lighthouse; do
        mkdir -p "$d"
        chown www-data:www-data "$d"
    done
fi
mkdir -p /var/tmp/panelalpha-backup
chown www-data:www-data /var/tmp/panelalpha-backup
chmod 1777 /var/tmp/panelalpha-backup
# s6 supervises core's processes: one directory per service under
# /run/service, copied fresh on every start so no stale supervise/ state from a
# previous run survives. The queue workers are generated into the same
# directory from QUEUE_WORKERS; see App\Support\QueueWorkers.
rm -rf /run/service
mkdir -p /run/service
cp -a /etc/s6/core/. /run/service/
php /var/www/html/artisan system:queue-workers:sync
# A restart SIGKILLs the unsupervised `schedule:run`, leaving its
# withoutOverlapping() mutexes in the persistent file cache; clear them so an
# interrupted command runs on the next tick instead of being skipped.
php /var/www/html/artisan schedule:clear-cache >/dev/null 2>&1 || true
# nginx.conf believes forwarded headers on :80 from the loopback and from the
# bridge gateway -- the host webserver's /panelalpha-sso hop -- and nothing
# else. The gateway differs per host, so it is read here from the default
# route. No route, no file: nginx then trusts the loopback alone.
write_trusted_proxy_conf() {
    local hex gw
    rm -f /etc/nginx/pa-trusted-proxy.conf
    hex=$(awk '$2 == "00000000" { print $3; exit }' /proc/net/route 2>/dev/null || true)
    [ ${#hex} = 8 ] || return 0
    gw=$(printf '%d.%d.%d.%d' "0x${hex:6:2}" "0x${hex:4:2}" "0x${hex:2:2}" "0x${hex:0:2}")
    printf 'set_real_ip_from %s;\nset $pa_gateway %s;\n' "$gw" "$gw" >/etc/nginx/pa-trusted-proxy.conf
}
write_trusted_proxy_conf

exec s6-svscan /run/service
