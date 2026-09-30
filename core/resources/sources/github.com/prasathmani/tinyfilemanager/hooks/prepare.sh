#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not), and writes its bcrypt hash where the container reads it.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/tinyfm"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'TFM_ADMIN_USER=admin\nTFM_ADMIN_PASSWORD=%s\n' \
        "$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)" > "${STORE}/admin.env")
    echo "[panelalpha] tinyfilemanager: generated the admin password"
fi
chmod 600 "${STORE}/admin.env"

# Hashed on every deploy, so a password edited in admin.env takes effect on redeploy.
mkdir -p panelalpha/tinyfm
TFM_ADMIN_PASSWORD="$(sed -n 's/^TFM_ADMIN_PASSWORD=//p' "${STORE}/admin.env")" php -r '
    $p = getenv("TFM_ADMIN_PASSWORD");
    if ($p === false || strlen($p) < 12) { fwrite(STDERR, "TFM_ADMIN_PASSWORD missing or too short\n"); exit(1); }
    echo password_hash($p, PASSWORD_BCRYPT), "\n";' > panelalpha/tinyfm/admin.hash
chmod 644 panelalpha/tinyfm/admin.hash
