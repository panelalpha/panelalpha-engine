#!/bin/bash
# Hive-Pal pulls one ~400 MB app image plus postgres. Require 3 GB free.
REQUIRED_SPACE=$((3 * 1024 * 1024))
AVAILABLE_SPACE=$(df -k . | awk 'NR==2 {print $4}')
if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_SPACE" ]; then
    echo "Error: less than 3 GB of disk space available for Hive-Pal." >&2
    exit 1
fi
