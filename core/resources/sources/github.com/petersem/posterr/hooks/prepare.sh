#!/bin/bash
# Generates one password once into ~/.panelalpha/posterr/ (survives redeploys;
# ~/project does not): HTTP Basic for the proxy and Posterr's settings password.
set -e
cd ~/project

say() { echo "[panelalpha] posterr: $*" >&2; }

STORE="${HOME}/.panelalpha/posterr"
mkdir -p "${STORE}/auth"
chmod 700 "${HOME}/.panelalpha" "${STORE}" "${STORE}/auth"

if [ ! -f "${STORE}/admin-password" ]; then
    (umask 077; openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24 > "${STORE}/admin-password")
    say "generated the admin password"
fi
PW="$(cat "${STORE}/admin-password")"

if [ ! -f "${STORE}/admin.env" ]; then
    (umask 077; printf 'POSTERR_PASSWORD=%s\n' "${PW}" > "${STORE}/admin.env")
fi
# Kept once written, so a user added by hand survives a redeploy.
if [ ! -f "${STORE}/auth/htpasswd" ]; then
    (umask 077; printf 'admin:%s\n' "$(openssl passwd -apr1 -stdin < "${STORE}/admin-password")" > "${STORE}/auth/htpasswd")
fi
if [ ! -f "${STORE}/credentials.txt" ]; then
    (umask 077; cat > "${STORE}/credentials.txt" <<NOTE_EOF
Posterr for this account
========================

  Poster wall (public):   /
  Settings (HTTP Basic):  /settings   user: admin   password: ${PW}
  Settings page password (asked again by Posterr itself): ${PW}

Created on the first deploy; a redeploy never resets it. The README default
settings password is replaced before the app starts.
NOTE_EOF
    )
    say "credentials written to ${STORE}/credentials.txt"
fi
chmod 600 "${STORE}/admin-password" "${STORE}/admin.env" "${STORE}/auth/htpasswd" "${STORE}/credentials.txt"

# The uid is only known here. Group 0 is what nginx-unprivileged expects.
cat > docker-compose.override.yml <<OVR
# Written by hooks/prepare.sh on every deploy.
services:
  proxy:
    user: "$(id -u):0"
OVR
say "prepare complete"
