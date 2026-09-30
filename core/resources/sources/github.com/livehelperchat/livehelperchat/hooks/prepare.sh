#!/bin/bash
# The installer writes lhc_web/settings/settings.ini.php (DB login, secret hash),
# which a redeploy would wipe with ~/project; it is bind-mounted from here.
set -e
STORE="${HOME}/.panelalpha/lhc"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
[ -e "${STORE}/settings.ini.php" ] || (umask 077; : > "${STORE}/settings.ini.php")
chmod 600 "${STORE}/settings.ini.php"
