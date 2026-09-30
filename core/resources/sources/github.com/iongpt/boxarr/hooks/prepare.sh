#!/bin/bash
# Generates the admin password once, in ~/.panelalpha (survives redeploys;
# ~/project does not), and rewrites the nginx htpasswd from it every deploy.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/boxarr"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/admin.txt" ]; then
    pw="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    (umask 077; printf 'user=admin\npassword=%s\n' "$pw" > "${STORE}/admin.txt")
    echo "[boxarr] generated the admin password into ${STORE}/admin.txt"
fi
chmod 600 "${STORE}/admin.txt"

# Rewritten in place (same inode) so the running proxy's file mount sees it.
# 0644: nginx runs as uid 101 in its container; the file holds only a hash.
user="$(sed -n 's/^user=//p' "${STORE}/admin.txt")"
pw="$(sed -n 's/^password=//p' "${STORE}/admin.txt")"
touch "${STORE}/htpasswd"
printf '%s:%s\n' "$user" "$(printf '%s' "$pw" | openssl passwd -apr1 -stdin)" > "${STORE}/htpasswd"
chmod 644 "${STORE}/htpasswd"

chmod +r panelalpha/boxarr-proxy.conf
