#!/bin/bash
#
# Recreates sftp when config/sftp/entrypoint.sh changed since its container started: `up -d`
# does not, as a mounted file is not part of the compose definition.
#
#   bash scripts/refresh-sftp-entrypoint.sh /opt/panelalpha/shared-hosting

set -euo pipefail

dir="${1:?usage: refresh-sftp-entrypoint.sh <engine dir>}"
entry="$dir/config/sftp/entrypoint.sh"
stamp="$dir/config/sftp/.entrypoint.sha256"

if [ ! -f "$entry" ] || [ ! -f "$dir/docker-compose.yml" ]; then
    exit 0
fi

sum=$(sha256sum "$entry" | cut -d' ' -f1)
if [ "$(cat "$stamp" 2>/dev/null || true)" = "$sum" ]; then
    exit 0
fi

if [ -n "$(cd "$dir" && docker compose ps -qa sftp 2>/dev/null)" ]; then
    (cd "$dir" && docker compose up -d --force-recreate --no-deps sftp)
    echo "SFTP container recreated: its entry point changed"
fi

printf '%s\n' "$sum" >"$stamp"
