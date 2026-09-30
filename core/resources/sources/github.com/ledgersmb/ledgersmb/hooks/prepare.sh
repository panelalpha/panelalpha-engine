#!/bin/bash
# Generate the postgres superuser password once, outside ~/project (wiped every
# deploy); it is baked into the data volume and needed at /setup.pl.
set -e
DIR="${HOME}/.panelalpha/ledgersmb"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/db.env" ]; then
    PW="$(openssl rand -hex 20)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "${PW}" > "${DIR}/db.env"
      printf 'LedgerSMB first-run setup: open <site>/setup.pl and sign in with\n  user: postgres\n  password: %s\n' "${PW}" > "${DIR}/credentials.txt" )
    echo "[ledgersmb] generated the postgres password -> ${DIR}/credentials.txt" >&2
fi
