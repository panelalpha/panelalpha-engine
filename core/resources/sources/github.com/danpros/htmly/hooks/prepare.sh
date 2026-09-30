#!/bin/bash
# Seed HTMLy's writable trees into ~/.panelalpha once; the compose override
# mounts them back over the fresh checkout on every deploy.
set -e
cd ~/project
DATA="${HOME}/.panelalpha/htmly"
mkdir -p "$DATA"
chmod 700 "$DATA"
if [ ! -d "$DATA/config" ]; then
    cp -a config "$DATA/config"
    echo "[htmly] seeded config into $DATA"
fi
mkdir -p "$DATA/content" content
