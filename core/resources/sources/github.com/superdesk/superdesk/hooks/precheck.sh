#!/bin/bash
# Server (REST, websocket, Celery, content API) ~1.4 GB, Elasticsearch ~0.95 GB,
# MongoDB and the rest ~0.2 GB: below ~3 GB Elasticsearch is OOM-killed in a loop.
REQUIRED_MB=3072
limit=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null)
case "$limit" in ''|max|*[!0-9]*) exit 0 ;; esac
mb=$((limit / 1024 / 1024))
if [ "$mb" -lt "$REQUIRED_MB" ]; then
    echo "Error: Superdesk needs a memory limit of at least ${REQUIRED_MB} MB (this account has ${mb} MB)." >&2
    exit 1
fi
