#!/bin/sh
# Seeds /wiki/data/config.yaml before Wiki-Go first starts, so it never writes
# its built-in admin/admin. Wiki-Go fills in every other setting itself.
set -eu
CONF=/wiki/data/config.yaml
if [ -s "$CONF" ]; then
    echo "Wiki-Go config exists; admin left as is"
    exit 0
fi
[ -n "${WIKIGO_ADMIN_PASSWORD:-}" ] || { echo "WIKIGO_ADMIN_PASSWORD unset" >&2; exit 1; }
HASH=$(htpasswd -nbBC 12 "$WIKIGO_ADMIN_USER" "$WIKIGO_ADMIN_PASSWORD" | cut -d: -f2-)
TMP="$CONF.tmp"
cat > "$TMP" <<YAML
server:
    host: "0.0.0.0"
    port: 8080
    allow_insecure_cookies: false
    # The engine proxy reaches the app through the account's Docker bridge.
    trusted_proxies: ["172.16.0.0/12"]
wiki:
    root_dir: "data"
    documents_dir: "documents"
    title: "Wiki-Go"
    owner: "${WIKIGO_OWNER}"
    timezone: "UTC"
    private: false
users:
    - username: ${WIKIGO_ADMIN_USER}
      password: "${HASH}"
      role: admin
YAML
mv "$TMP" "$CONF"
# The volume is created by this container, as root; Wiki-Go runs as uid 1000.
chown -R 1000:1000 /wiki/data
chmod 600 "$CONF"
echo "Wiki-Go config seeded with admin ${WIKIGO_ADMIN_USER}"
