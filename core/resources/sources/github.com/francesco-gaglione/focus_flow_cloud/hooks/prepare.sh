#!/bin/bash
# Generates the JWT secret, DB password and VAPID key once in ~/.panelalpha
# (survives redeploys; a new VAPID key would drop every push subscription).
set -e
STORE="${HOME}/.panelalpha/focusflow"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/app.env" ]; then
    db="$(openssl rand -hex 24)"
    # Raw P-256 private scalar, base64url without padding (web-push format).
    vapid="$(openssl ecparam -name prime256v1 -genkey -noout -outform DER | tail -c +8 | head -c 32 | base64 | tr '+/' '-_' | tr -d '=\n')"
    (umask 077; cat > "${STORE}/app.env" <<EOT
JWT_SECRET=$(openssl rand -hex 32)
VAPID_PRIVATE_KEY=${vapid}
POSTGRES_PASSWORD=${db}
EOT
)
    echo "[panelalpha] focusflow: generated secrets into ${STORE}/app.env"
fi
chmod 600 "${STORE}/app.env"
