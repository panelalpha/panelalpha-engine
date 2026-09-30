#!/bin/bash
# Writes the login into ~/.panelalpha/hassconf/auth.env on every deploy. The
# login is the engine's (`credentials:` in panelalpha.yaml); only its sha256
# reaches the container. Without USERNAME/PASSWORD the configurator serves every
# route, file writes and /api/exec_command included, to anyone.
set -e
cd ~/project

say() { echo "[panelalpha] hass-configurator: $*" >&2; }

STORE="${HOME}/.panelalpha/hassconf"
AUTH_ENV="${STORE}/auth.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
HASH="$(printf '%s' "${HC_LOGIN_PASSWORD}" | sha256sum | cut -d' ' -f1)"
(
    umask 077
    # Only the sha256 reaches the container; the app compares {sha256} natively.
    printf 'HC_USERNAME=%s\nHC_PASSWORD={sha256}%s\n' "${HC_USERNAME}" "${HASH}" > "${AUTH_ENV}"
)
chmod 600 "${AUTH_ENV}"
say "prepare complete"
