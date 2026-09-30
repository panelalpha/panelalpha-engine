#!/bin/bash
# Generates the Sshwifty shared key once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The key is the only thing between the web and the relay.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/sshwifty"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/sharedkey.env" ]; then
    (umask 077; printf 'SSHWIFTY_SHAREDKEY=%s\n' "$(openssl rand -base64 36 | tr -d '/+=\n')" > "${STORE}/sharedkey.env")
    echo "[panelalpha] sshwifty: generated the shared key in ${STORE}/sharedkey.env"
fi
chmod 600 "${STORE}/sharedkey.env"
