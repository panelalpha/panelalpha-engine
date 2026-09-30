#!/bin/bash
# Generates the PostgreSQL password once into ~/.panelalpha/meme-search (the
# database volume keeps the first one; upstream ships postgres/postgres).
set -e
DIR="${HOME}/.panelalpha/meme-search"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/db.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_PASSWORD=%s\n' "${pw}" > "${DIR}/db.env"
      printf 'DATABASE_URL=postgres://postgres:%s@meme-search-db:5432/meme_search\n' "${pw}" > "${DIR}/app.env" )
    echo "[meme-search] generated the database password -> ${DIR}" >&2
fi
chmod 600 "${DIR}/db.env" "${DIR}/app.env"
