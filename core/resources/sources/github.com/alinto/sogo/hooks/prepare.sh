#!/bin/bash
# Runs on the account, in ~/project, after the clone and before the build.
set -e

STATE="$HOME/.panelalpha/sogo"
ENV_FILE="$STATE/sogo.env"

# ~/project is emptied and re-cloned on every deploy, so the database password
# cannot live there: regenerating it would leave the app unable to open the
# database it already has. ~/.panelalpha/ survives, and is the only directory
# that does. The first login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs.
mkdir -p "$STATE"
chmod 700 "$HOME/.panelalpha" "$STATE"

if [ ! -f "$ENV_FILE" ]; then
    umask 077
    # Hex only, deliberately: these passwords are substituted into
    # `mysql://user:pass@host/db/table` URLs in sogo.conf, where a `@`, `/` or
    # `:` would be read as URL syntax.
    {
        echo "MARIADB_DATABASE=sogo"
        echo "MARIADB_USER=sogo"
        echo "MARIADB_PASSWORD=$(openssl rand -hex 16)"
        echo "MARIADB_ROOT_PASSWORD=$(openssl rand -hex 16)"
        # Named by the account owner. SOGo is an IMAP/SMTP client and this
        # platform hosts no mailbox, so both are empty until somebody points
        # them at their own provider, e.g.
        #   SOGO_IMAP_SERVER=imaps://imap.example.com:993
        #   SOGO_SMTP_SERVER=smtp://smtp.example.com:587
        echo "SOGO_IMAP_SERVER="
        echo "SOGO_SMTP_SERVER="
    } > "$ENV_FILE"
    echo "[panelalpha] SOGo: generated $ENV_FILE"
fi
# An older deploy kept the first login here too; the engine adopted it.
sed -i '/^SOGO_ADMIN_\(USER\|PASSWORD\)=/d' "$ENV_FILE"
chmod 600 "$ENV_FILE"
