#!/bin/bash
set -e

# portkey is driven entirely by one config.yml, read once at startup. That file
# is the operator's link list — the only mutable state the app has — so it must
# live where a redeploy cannot wipe it. ~/project is deleted and re-cloned every
# rebuild (engine#173); ~/.panelalpha is the one writable, rebuild-surviving
# directory the account owns.
#
# Seed it once from the config shipped with the recipe. If it already exists,
# leave it alone: a customer's edited links must never be clobbered by a
# redeploy. World-readable (0644), because the container's non-root user reads
# it and it holds no secret — the whole file is a public link list.
dst="$HOME/.panelalpha/portkey"
mkdir -p "$dst"

if [ ! -f "$dst/config.yml" ]; then
    cp "$HOME/project/panelalpha/portkey/config.yml" "$dst/config.yml"
fi
chmod 0644 "$dst/config.yml"
chmod 0755 "$dst"
