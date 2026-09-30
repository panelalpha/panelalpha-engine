#!/bin/bash
# Generates AUTH_SECRET and the database passwords once into ~/.panelalpha/aptabase;
# the volumes keep the first passwords, so they are never regenerated.
set -e
DIR="${HOME}/.panelalpha/aptabase"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    pg="$(openssl rand -hex 24)"
    ch="$(openssl rand -hex 24)"
    ( umask 077
      printf 'POSTGRES_USER=aptabase\nPOSTGRES_PASSWORD=%s\n' "${pg}" > "${DIR}/db.env"
      printf 'CLICKHOUSE_USER=aptabase\nCLICKHOUSE_PASSWORD=%s\n' "${ch}" > "${DIR}/events.env"
      printf 'AUTH_SECRET=%s\nDATABASE_URL=Server=aptabase_db;Port=5432;User Id=aptabase;Password=%s;Database=aptabase\nCLICKHOUSE_URL=Host=aptabase_events_db;Port=8123;Username=aptabase;Password=%s\n' \
        "$(openssl rand -hex 32)" "${pg}" "${ch}" > "${DIR}/app.env" )
    echo "[aptabase] generated AUTH_SECRET and the database passwords -> ${DIR}" >&2
fi
chmod 600 "${DIR}"/*.env
