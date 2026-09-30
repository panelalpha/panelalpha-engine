#!/bin/bash
# Seeds Mylar's config.ini on the first boot only: form login (authentication=2)
# and the API with a generated key. An existing config.ini is never touched.
set -e

INI=/config/mylar/config.ini
: "${MYLAR_USER:?MYLAR_USER is not set; app-credentials.env is missing}"
: "${MYLAR_PASSWORD:?MYLAR_PASSWORD is not set; app-credentials.env is missing}"
: "${MYLAR_API_KEY:?MYLAR_API_KEY is not set; prepare.sh did not run}"

if [ ! -s "${INI}" ]; then
    mkdir -p /config/mylar
    # Mylar compares the plain value (encrypt_passwords defaults to False).
    (umask 077; cat > "${INI}" <<EOF
[General]
destination_dir = /comics

[Interface]
http_host = 0.0.0.0
http_port = 8090
http_username = ${MYLAR_USER}
http_password = ${MYLAR_PASSWORD}
authentication = 2

[API]
api_enabled = True
api_key = ${MYLAR_API_KEY}
EOF
    )
    echo "[panelalpha] seeded ${INI} with form login and API key"
else
    echo "[panelalpha] ${INI} exists; left unchanged"
fi

# The library volume is created root-owned; the image's init only chowns /config.
chown "${PUID:-911}:${PGID:-911}" /comics

# The image's init chowns /config to PUID/PGID and starts Mylar.
exec /init
