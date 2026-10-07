#!/bin/bash
# Generates Overleaf's secrets once, in ~/.panelalpha (survives redeploys;
# ~/project does not). A new invite token secret invalidates every invite and
# link-sharing token already stored; a new session secret logs everyone out,
# which the image's own per-container fallback did on every rebuild.
set -e

STORE="${HOME}/.panelalpha/overleaf"
ENV_FILE="${STORE}/app.env"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

for name in OVERLEAF_INVITE_TOKEN_SECRET OVERLEAF_SESSION_SECRET; do
    if ! grep -q "^${name}=" "${ENV_FILE}" 2>/dev/null; then
        (umask 077
         printf '%s=%s\n' "${name}" "$(openssl rand -hex 32)" >> "${ENV_FILE}")
    fi
done
chmod 600 "${ENV_FILE}"
