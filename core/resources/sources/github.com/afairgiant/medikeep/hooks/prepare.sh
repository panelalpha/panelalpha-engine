#!/bin/bash
# Generates the DB password and SECRET_KEY once into ~/.panelalpha/medikeep
# (~/project is wiped on every deploy; the database keeps the first password).
set -e
DIR="${HOME}/.panelalpha/medikeep"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/secrets.env" ]; then
    DBPW="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\nDB_PASSWORD=%s\nSECRET_KEY=%s\n' \
        "${DBPW}" "${DBPW}" "$(openssl rand -hex 32)" > "${DIR}/secrets.env" )
    echo "[medikeep] generated DB password and SECRET_KEY -> ${DIR}/secrets.env" >&2
fi
chmod 600 "${DIR}/secrets.env"
