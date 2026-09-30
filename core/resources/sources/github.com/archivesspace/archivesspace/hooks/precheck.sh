#!/bin/bash
# ArchivesSpace ~1.5 GB (1 GB heap), Solr ~0.75 GB, MySQL ~0.55 GB, plus the
# account daemon: ~2.9 GB measured, so the 2500 MB default is too tight.
REQUIRED_MB=3072
limit=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || cat /sys/fs/cgroup/memory/memory.limit_in_bytes 2>/dev/null)
case "$limit" in ''|max|*[!0-9]*) exit 0 ;; esac
mb=$((limit / 1024 / 1024))
if [ "$mb" -lt "$REQUIRED_MB" ]; then
    echo "Error: ArchivesSpace needs a memory limit of at least ${REQUIRED_MB} MB (this account has ${mb} MB)." >&2
    exit 1
fi
