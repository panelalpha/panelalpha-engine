#!/bin/bash
# Generates the Elasticsearch password once into ~/.panelalpha/tubearchivist
# (~/project is wiped on every deploy; the ES data volume keeps the first one).
set -e
DIR="${HOME}/.panelalpha/tubearchivist"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/secrets.env" ]; then
    ( umask 077
      printf 'ELASTIC_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "${DIR}/secrets.env" )
    echo "[tubearchivist] generated ELASTIC_PASSWORD -> ${DIR}/secrets.env" >&2
fi
chmod 600 "${DIR}/secrets.env"
