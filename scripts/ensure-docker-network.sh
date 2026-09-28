#!/usr/bin/env bash
# Creates pash-default-network unless it already exists.
#
#   bash scripts/ensure-docker-network.sh [MTU]
#
# A firewall reload -- CSF's `csf -r`, or a boot that starts CSF after Docker --
# flushes the iptables chains dockerd installs at start. Networks that exist
# keep working, but every new `docker network create` then fails with
# `iptables: No chain/target/match by that name` until the daemon restarts and
# puts its chains back. A reinstall is the first thing that needs a new network
# (uninstall removed it), so it died there (#68). Restart Docker once and retry.
set -u

NAME=pash-default-network
MTU=${1:-1500}

docker network inspect "$NAME" >/dev/null 2>&1 && exit 0

create() { docker network create "$NAME" --opt com.docker.network.driver.mtu="$MTU" 2>&1; }

if out=$(create); then
    exit 0
fi
echo "$out" >&2
if ! printf '%s' "$out" | grep -qiE 'iptables|no chain'; then
    exit 1
fi

echo "Docker's iptables chains are missing (a firewall reload flushes them); restarting Docker and retrying" >&2
systemctl reset-failed docker.service 2>/dev/null || true
service docker restart || systemctl restart docker || exit 1
for _ in $(seq 1 30); do
    docker info >/dev/null 2>&1 && break
    sleep 1
done

if out=$(create); then
    exit 0
fi
echo "$out" >&2
exit 1
