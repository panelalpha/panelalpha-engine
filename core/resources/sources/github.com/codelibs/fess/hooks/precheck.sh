#!/bin/bash
# OpenSearch (1 GB heap) plus Fess, which grows while a crawl job's JVM runs,
# need about 2.5 GB, so the 2500 MB default is too tight.
REQUIRED_MB=3072
limit=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null)
case "$limit" in ''|max|*[!0-9]*) exit 0 ;; esac
mb=$((limit / 1024 / 1024))
if [ "$mb" -lt "$REQUIRED_MB" ]; then
    echo "Error: Fess needs a memory limit of at least ${REQUIRED_MB} MB (this account has ${mb} MB)." >&2
    exit 1
fi
