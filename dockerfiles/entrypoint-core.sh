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
# s6 supervises core's processes: one directory per service under
# /run/service, copied fresh on every start so no stale supervise/ state from a
# previous run survives. The queue workers are generated into the same
# directory from QUEUE_WORKERS; see App\Support\QueueWorkers.
rm -rf /run/service
mkdir -p /run/service
cp -a /etc/s6/core/. /run/service/
php /var/www/html/artisan system:queue-workers:sync
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

# ufw does not filter ports Docker publishes on its own; the host firewall's
# hook in DOCKER-USER does. ufw puts it in place whenever it starts; this is
# the backstop for a host where Docker came up after it.
firewall_script=/opt/panelalpha/shared-hosting/scripts/firewall.sh
if [ -f "$firewall_script" ]; then
    timeout 30 nsenter --target 1 --all bash "$firewall_script" --apply || true
fi

# engine#519: the accounts' firewall lives in the host's iptables, which a
# reboot empties. Core starts with the host, and accounts started by Docker
# itself do not go through the engine.
tenant_script=/opt/panelalpha/shared-hosting/scripts/tenant-network-firewall.sh
if [ -f "$tenant_script" ]; then
    timeout 60 nsenter --target 1 --all sh "$tenant_script" || true
fi

exec s6-svscan /run/service
