#!/bin/bash
# calcom/cal.com:v6.2.0 is 1.6 GB compressed but 6.4 GB unpacked (8 GB in a
# containerd store), plus ~0.9 GB for postgres and redis. Require 10 GB free.
REQUIRED_SPACE=$((10 * 1024 * 1024))
AVAILABLE_SPACE=$(df -k . | awk 'NR==2 {print $4}')
if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_SPACE" ]; then
    echo "Error: less than 10 GB of disk space available for Cal.diy." >&2
    exit 1
fi
