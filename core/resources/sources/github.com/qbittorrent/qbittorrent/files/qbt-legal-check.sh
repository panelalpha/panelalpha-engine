#!/bin/sh
# qbittorrent-nox exits unless its legal notice is confirmed; that is the customer's call.
v=$(echo "${QBT_LEGAL_NOTICE:-}" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')
if [ "$v" != "confirm" ]; then
    echo "qbittorrent: missing project environment variable QBT_LEGAL_NOTICE. qBittorrent is a file sharing program: any content you share is your sole responsibility. Set QBT_LEGAL_NOTICE=confirm to accept its legal notice, then redeploy." >&2
    exit 1
fi
echo "qbittorrent: legal notice confirmed"
