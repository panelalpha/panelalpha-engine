#!/bin/bash
# Generates SUPERSET_SECRET_KEY once into ~/.panelalpha/superset (~/project is
# wiped on every deploy; the key encrypts stored database credentials).
set -e
DIR="${HOME}/.panelalpha/superset"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/secrets.env" ]; then
    ( umask 077
      printf 'SUPERSET_SECRET_KEY=%s\n' "$(openssl rand -base64 42 | tr -d '\n')" > "${DIR}/secrets.env" )
    echo "[superset] generated SUPERSET_SECRET_KEY -> ${DIR}/secrets.env" >&2
fi
chmod 600 "${DIR}/secrets.env"
