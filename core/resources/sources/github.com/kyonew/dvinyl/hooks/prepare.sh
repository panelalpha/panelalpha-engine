#!/bin/bash
# PASSJWT and SESSION_SECRET (DVinyl refuses to start without them), created
# once in ~/.panelalpha: ~/project is emptied on every deploy.
set -e
STORE="${HOME}/.panelalpha/dvinyl"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    (umask 077
     printf 'PASSJWT=%s\nSESSION_SECRET=%s\n' "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"
