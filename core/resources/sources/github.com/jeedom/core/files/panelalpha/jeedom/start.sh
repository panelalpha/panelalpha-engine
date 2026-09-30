#!/bin/bash
# Wraps the image's own /root/init.sh (install on first boot, then MariaDB,
# Apache, cron). A background loop replaces the install-time admin/admin with the
# generated password; the healthcheck, and so the proxy, waits for its marker.
rm -f /run/panelalpha-seeded
(
    for i in $(seq 1 360); do
        if su -s /bin/sh www-data -c 'php /panelalpha/seed.php' 2>/dev/null; then
            touch /run/panelalpha-seeded
            exit 0
        fi
        sleep 5
    done
    echo "[panelalpha] jeedom: admin password not seeded after 30 minutes" >&2
) &
exec bash /root/init.sh
