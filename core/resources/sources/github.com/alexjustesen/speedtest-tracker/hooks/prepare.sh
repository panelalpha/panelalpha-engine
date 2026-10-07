#!/bin/bash
# Generate APP_KEY once, where a redeploy will not wipe it (~/project is
# emptied on every deploy), and seed .env. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[speedtest] $*" >&2; }

STORE="${HOME}/.panelalpha/speedtest"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (
        umask 077
        cat > "${STORE}/app.env" <<ENV_EOF
# Written once. APP_KEY encrypts sessions and stored settings.
APP_KEY=base64:$(openssl rand -base64 32)
ENV_EOF
    )
    say "APP_KEY written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi
# An older deploy kept the admin login here too; the engine adopted it.
sed -i '/^ADMIN_\(EMAIL\|PASSWORD\)=/d' "${STORE}/app.env"

# Defaults the panel's env vars can override; nothing secret here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
write_default TZ UTC
write_default APP_TIMEZONE UTC
write_default DISPLAY_TIMEZONE UTC
