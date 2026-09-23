#!/bin/sh
# b1gMail's scheduler. cron.php is what runs the POP3 catchall gateway
# (cron.php:133), the calendar notifications and every cleanup job; without it
# a mailbox never receives anything. The engine has no cron stage a recipe can
# declare and the per-project crontab feature is not wired into the dind
# template, so this runs as a background process in the app container.
#
# cron.php gates itself on prefs.cron_interval, so waking it every minute costs
# one PHP process and does the work at whatever interval the ACP is set to.
set -u

cd /app/src || exit 0
echo "[panelalpha] b1gmail: cron loop started (wakes every 60s, gated by prefs.cron_interval)" >&2

while true; do
    sleep 60
    out=$(php cron.php 2>&1) || echo "[panelalpha] b1gmail: cron.php exited non-zero: $(echo "$out" | tail -3)" >&2
done
