#!/bin/bash
# Seed Yellow's writable trees into ~/.panelalpha once; the compose override
# mounts them back over the fresh checkout on every deploy.
set -e
cd ~/project
DATA="${HOME}/.panelalpha/yellow"
mkdir -p "$DATA"
chmod 700 "$DATA"
for d in content media system; do
    if [ ! -d "$DATA/$d" ]; then
        cp -a "$d" "$DATA/$d"
        echo "[yellow] seeded $d into $DATA"
    fi
done
