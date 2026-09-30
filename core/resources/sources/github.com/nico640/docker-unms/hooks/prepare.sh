#!/bin/bash
# Generate the UISP admin password once, where a redeploy will not wipe it
# (~/project is emptied every deploy).
set -e
cd ~/project

STORE_DIR="${HOME}/.panelalpha/uisp"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        printf 'UISP_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
UISP on this account
====================

UISP's first-run setup is closed: on the first deploy a one-shot service
completed it over the internal network with the admin below, before the
public site was started.

ADMIN LOGIN
  URL:      <this account's URL>/nms/login
  Username: admin   (or UISP_ADMIN_USER, if set in the project env first)
  Password: ${ADMIN_PASSWORD}

Change the password in the UI after the first login; a redeploy does not
reset it. The setup did not accept Ubiquiti's EULA on your behalf
(eulaConfirmed=false) and opted out of Sentry/Logentries error reporting.

The vault/backup passphrase UISP generated at setup is in the uisp-config
volume, /config/panelalpha/setup.json inside the uisp container.
NOTE_EOF
    )
    echo "[uisp] admin password written to ${STORE_DIR}" >&2
else
    echo "[uisp] reusing ${ADMIN_ENV}" >&2
fi

touch .env
