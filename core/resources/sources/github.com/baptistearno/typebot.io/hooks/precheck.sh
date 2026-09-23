#!/bin/bash
# The builder and viewer images are ~6 GB each; with Postgres and layers the
# stack needs well over 12 GB of disk. Fail closed before the clone.
REQUIRED_SPACE=$((15 * 1024 * 1024)) # 15 GiB in KiB
AVAILABLE_SPACE=$(df -k . | awk 'NR==2 {print $4}')
if [ "$AVAILABLE_SPACE" -lt "$REQUIRED_SPACE" ]; then
    echo "Error: Typebot needs at least 15GB of free disk (builder + viewer images are ~6GB each)."
    exit 1
fi

# builder (~1G) + viewer (~0.75G) + Postgres. Two Next.js servers are not small.
REQUIRED_MEM_KB=$((2 * 1024 * 1024)) # 2 GiB total RAM
TOTAL_MEM_KB=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
if [ -z "$TOTAL_MEM_KB" ] || [ "$TOTAL_MEM_KB" -lt "$REQUIRED_MEM_KB" ]; then
    echo "Error: Typebot (builder + viewer + Postgres) needs at least 2GB of RAM (found ${TOTAL_MEM_KB:-unknown} kB)."
    exit 1
fi
