#!/bin/bash
# chatwoot (0.75 GB compressed, 2.7 GB on disk) plus pgvector and redis come to
# ~3.5 GB, before the database grows. Require 5 GB free.
REQUIRED_SPACE=$((5 * 1024 * 1024))
AVAILABLE_SPACE=$(df -k . | awk 'NR==2 {print $4}')
if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_SPACE" ]; then
    echo "Error: less than 5 GB of disk space available for Chatwoot." >&2
    exit 1
fi
