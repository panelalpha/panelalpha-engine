#!/bin/bash
# Generates the Jeedom admin password once into ~/.panelalpha/jeedom/ (survives
# redeploys; ~/project does not). seed.php puts it in place of admin/admin.
set -e
cd ~/project

say() { echo "[panelalpha] jeedom: $*" >&2; }

STORE="${HOME}/.panelalpha/jeedom"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    PW="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    (
        umask 077
        printf 'JEEDOM_ADMIN_PASSWORD=%s\n' "${PW}" > "${STORE}/admin.env"
        cat > "${STORE}/credentials.txt" <<NOTE_EOF
Jeedom administrator for this account
=====================================

  username: admin
  password: ${PW}

Created on the first deploy; a redeploy never resets it. The upstream
install-time admin/admin is replaced before the site opens. A password changed
in Jeedom (Settings > System > Users) is kept.
NOTE_EOF
    )
    say "admin credentials written to ${STORE}/credentials.txt"
fi
chmod 600 "${STORE}/admin.env" "${STORE}/credentials.txt"
say "prepare complete"
