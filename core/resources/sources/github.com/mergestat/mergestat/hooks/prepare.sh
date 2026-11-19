#!/bin/bash
# The login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs. Here the two secrets
# upstream's compose file defaults to published values (JWT_SECRET=secret,
# ENCRYPTION_SECRET=password) are generated once, in ~/.panelalpha (survives
# redeploys; ~/project does not): ENCRYPTION_SECRET must never change, or the
# git host tokens MergeStat stored can no longer be read.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/mergestat"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    (umask 077
     printf 'MERGESTAT_JWT_SECRET=%s\nMERGESTAT_ENCRYPTION_SECRET=%s\n' \
         "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "${STORE}/secrets.env")
fi
chmod 600 "${STORE}/secrets.env"

# The worker and the console run as non-root users that must read the wrapper.
chmod a+rx panelalpha panelalpha/mergestat-run.sh
