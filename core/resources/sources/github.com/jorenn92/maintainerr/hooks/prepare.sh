#!/bin/bash
# Generates the Basic-auth password once, in ~/.panelalpha (survives redeploys;
# ~/project does not), and runs the proxy as the account so it can read it.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/maintainerr"
mkdir -p "${STORE}/auth"
chmod 700 "${HOME}/.panelalpha" "${STORE}" "${STORE}/auth"

if [ ! -f "${STORE}/admin-password" ]; then
    (umask 077; openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24 > "${STORE}/admin-password")
    echo "[panelalpha] maintainerr: generated the admin password"
fi
chmod 600 "${STORE}/admin-password"

# Kept once written, so a user added by hand survives a redeploy.
if [ ! -f "${STORE}/auth/htpasswd" ]; then
    (umask 077; printf 'admin:%s\n' "$(openssl passwd -apr1 -stdin < "${STORE}/admin-password")" > "${STORE}/auth/htpasswd")
fi
chmod 600 "${STORE}/auth/htpasswd"

# The uid is only known here. Group 0 is what nginx-unprivileged expects.
cat > docker-compose.override.yml <<OVR
# Written by hooks/prepare.sh on every deploy.
services:
  proxy:
    user: "$(id -u):0"
OVR
