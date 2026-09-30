#!/bin/bash
# Generates the session secrets once into ~/.panelalpha/chronoframe (~/project
# is wiped every deploy); later deploys reuse them. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

STORE="$HOME/.panelalpha/chronoframe"
ENV_FILE="$STORE/chronoframe.env"
mkdir -p "$STORE"
chmod 700 "$HOME/.panelalpha" "$STORE" 2>/dev/null || true

if [ ! -f "$ENV_FILE" ]; then
    (
        umask 077
        cat > "$ENV_FILE" <<ENV
NUXT_SESSION_PASSWORD=$(openssl rand -hex 32)
NUXT_OG_IMAGE_SECRET=$(openssl rand -hex 32)
ENV
    )
fi
# An older deploy kept the admin login here too; the engine adopted it.
sed -i '/^CFRAME_ADMIN_\(EMAIL\|PASSWORD\)=/d' "$ENV_FILE"
chmod 600 "$ENV_FILE"

docker pull ghcr.io/hoshinosuzumi/chronoframe:0.14.1 >/dev/null 2>&1 || true
