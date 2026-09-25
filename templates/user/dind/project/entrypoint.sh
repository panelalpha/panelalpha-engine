#!/bin/bash
set -e
echo "$(hostname -i) $(hostname) $(hostname).localhost" >> /etc/hosts
# Prefer IPv4 over IPv6 for all outbound connections (affects Docker daemon registry pulls).
echo "precedence ::ffff:0:0/96  100" >> /etc/gai.conf
# Force Docker daemon (Go binary) to prefer IPv4 over AAAA when pulling images.
# gai.conf has no effect on Go programs; GODEBUG=preferIPv4=1 is the correct knob (Go 1.21+).
echo 'export GODEBUG=preferIPv4=1' > /etc/default/docker

# One-shot bootstrap only (passwd, daemon.json, cgroup). Long-running processes
# are supervised by s6 below.
for f in /entrypoint.d/*.sh; do
  [ -f "$f" ] || continue
  echo "[entrypoint] running $f"
  bash "$f"
done

# s6 supervises dockerd, cron and cloudflared: one directory per service,
# copied into /run (a tmpfs) so every start begins with no stale state.
mkdir -p /run/service
cp -a /etc/s6/account/. /run/service/
exec s6-svscan /run/service
