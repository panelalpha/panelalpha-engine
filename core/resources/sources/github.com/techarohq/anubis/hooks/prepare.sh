#!/bin/bash
# Generates anubis's ed25519 signing key once, in ~/.panelalpha (survives
# redeploys; ~/project does not). A new key would invalidate every pass
# already issued.
set -e

STORE="${HOME}/.panelalpha/anubis"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/key.env" ]; then
    (umask 077
     printf 'ED25519_PRIVATE_KEY_HEX=%s\n' "$(openssl rand -hex 32)" > "${STORE}/key.env")
fi
chmod 600 "${STORE}/key.env"
