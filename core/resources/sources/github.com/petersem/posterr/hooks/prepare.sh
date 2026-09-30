#!/bin/bash
# Writes the proxy's HTTP Basic file from the login the engine keeps
# (`credentials:` in panelalpha.yaml, ~/.panelalpha/app-credentials.env, written
# before this hook runs). The same password is Posterr's settings password.
set -e
cd ~/project

say() { echo "[panelalpha] posterr: $*" >&2; }

STORE="${HOME}/.panelalpha/posterr"
mkdir -p "${STORE}/auth"
chmod 700 "${HOME}/.panelalpha" "${STORE}" "${STORE}/auth"

# Kept once written, so a user added by hand survives a redeploy.
if [ ! -f "${STORE}/auth/htpasswd" ]; then
    set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
    (umask 077; printf '%s:%s\n' "${POSTERR_USER}" "$(printf '%s' "${POSTERR_PASSWORD}" | openssl passwd -apr1 -stdin)" > "${STORE}/auth/htpasswd")
    say "wrote the HTTP Basic login"
fi
chmod 600 "${STORE}/auth/htpasswd"

# The uid is only known here. Group 0 is what nginx-unprivileged expects.
cat > docker-compose.override.yml <<OVR
# Written by hooks/prepare.sh on every deploy.
services:
  proxy:
    user: "$(id -u):0"
OVR
say "prepare complete"
