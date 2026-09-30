#!/bin/bash
# Generate the Gateway token once, outside ~/project (wiped every deploy).
# A lan bind refuses to start without auth; this is the token the Control UI asks for.
set -e
STORE_DIR="${HOME}/.panelalpha/openclaw"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -s "${STORE_DIR}/gateway.env" ]; then
    (
        umask 077
        echo "OPENCLAW_GATEWAY_TOKEN=$(openssl rand -hex 32)" > "${STORE_DIR}/gateway.env"
    )
    echo "[openclaw] Gateway token written to ${STORE_DIR}/gateway.env" >&2
fi
