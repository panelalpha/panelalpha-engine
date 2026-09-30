#!/bin/bash
# Generates the MariaDB password once into ~/.panelalpha/facilmap
# (~/project is wiped on every deploy; the database keeps the first password).
set -e
DIR="${HOME}/.panelalpha/facilmap"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/secrets.env" ]; then
    PW="$(openssl rand -hex 24)"
    ( umask 077
      printf 'MARIADB_PASSWORD=%s\nDB_PASSWORD=%s\n' "${PW}" "${PW}" > "${DIR}/secrets.env" )
    echo "[facilmap] generated DB password -> ${DIR}/secrets.env" >&2
fi
chmod 600 "${DIR}/secrets.env"
