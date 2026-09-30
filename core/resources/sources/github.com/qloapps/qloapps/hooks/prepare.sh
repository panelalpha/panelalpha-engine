#!/bin/bash
# The installer writes config/settings.inc.php (DB login, cookie keys) and
# /.htaccess into ~/project, which every deploy wipes; both live here instead.
set -e
STORE="${HOME}/.panelalpha/qloapps"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
# The installer creates them 0666; the dir is 0700, keep the files 0600 too.
for f in "${STORE}/settings.inc.php" "${STORE}/htaccess"; do
    [ -f "$f" ] && chmod 600 "$f"
done
exit 0
