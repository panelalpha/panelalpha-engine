#!/bin/bash
# Generates the credentials once, in ~/.panelalpha (survives redeploys;
# ~/project does not), and rewrites the proxy's htpasswd from them every deploy.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/crontabui"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/auth.env" ]; then
    pw="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    (umask 077; printf 'BASIC_AUTH_USER=admin\nBASIC_AUTH_PWD=%s\n' "$pw" > "${STORE}/auth.env")
    echo "[crontab-ui] generated the admin password into ${STORE}/auth.env"
fi
chmod 600 "${STORE}/auth.env"

# Rewritten in place (same inode) so the running proxy's file mount sees it.
# 0644: nginx runs as uid 101 in its container; the file holds only a hash.
user="$(sed -n 's/^BASIC_AUTH_USER=//p' "${STORE}/auth.env")"
pw="$(sed -n 's/^BASIC_AUTH_PWD=//p' "${STORE}/auth.env")"
touch "${STORE}/htpasswd"
printf '%s:%s\n' "$user" "$(printf '%s' "$pw" | openssl passwd -apr1 -stdin)" > "${STORE}/htpasswd"
chmod 644 "${STORE}/htpasswd"

chmod +r panelalpha/crontabui-proxy.conf
