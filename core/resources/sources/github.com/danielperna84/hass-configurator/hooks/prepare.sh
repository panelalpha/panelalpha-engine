#!/bin/bash
# Generates the login once into ~/.panelalpha/hassconf/ (survives redeploys;
# ~/project does not). Without USERNAME/PASSWORD the configurator serves every
# route, file writes and /api/exec_command included, to anyone.
set -e
cd ~/project

say() { echo "[panelalpha] hass-configurator: $*" >&2; }

STORE="${HOME}/.panelalpha/hassconf"
AUTH_ENV="${STORE}/auth.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${AUTH_ENV}" ]; then
    # Letters and digits only: the app splits Basic auth on ':' and turns an
    # all-digit env value into an int.
    PASSWORD="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28)"
    HASH="$(printf '%s' "${PASSWORD}" | sha256sum | cut -d' ' -f1)"
    (
        umask 077
        # Only the sha256 reaches the container; the app compares {sha256} natively.
        printf 'HC_USERNAME=admin\nHC_PASSWORD={sha256}%s\n' "${HASH}" > "${AUTH_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
HASS Configurator login for this account (HTTP Basic auth)
==========================================================

  username: admin
  password: ${PASSWORD}

Created on the first deploy; a redeploy never resets it. To change it, write a
new HC_PASSWORD={sha256}<sha256 of the new password> into auth.env next to
this file and redeploy. Edited files live on the named volume hassconf-config
(/config in the container).
NOTE_EOF
    )
    say "login written to ${NOTE}"
fi
chmod 600 "${AUTH_ENV}" "${NOTE}"
say "prepare complete"
