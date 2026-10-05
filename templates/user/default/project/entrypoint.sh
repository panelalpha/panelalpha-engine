#!/bin/bash
set -e
echo "$(hostname -i) $(hostname) $(hostname).localhost" >> /etc/hosts

# One-shot setup: the passwd entry, Apache modules.
for f in /entrypoint-init.d/*.sh; do
  [ -f "$f" ] || continue
  echo "[entrypoint] running $f"
  bash "$f"
done

# s6 supervises redis, cron, the webserver and each PHP handler: one directory
# per service, written by the engine into the project's services/. A restart
# keeps the container's /run, so the scan dir is emptied first: a handler
# retired while the account was down must not come back.
rm -rf /run/service
mkdir -p /run/service
cp -a /etc/s6/account/. /run/service/
exec s6-svscan /run/service
