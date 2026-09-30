#!/bin/bash
# Generate SECRET_KEY_BASE and the ClickHouse password once (as upstream's
# configure.sh does), outside ~/project, which is wiped every deploy.
set -e
DIR="${HOME}/.panelalpha/swetrix"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/api.env" ]; then
    ch="$(openssl rand -hex 32)"
    ( umask 077
      printf 'CLICKHOUSE_PASSWORD=%s\n' "${ch}" > "${DIR}/clickhouse.env"
      printf 'SECRET_KEY_BASE=%s\nCLICKHOUSE_PASSWORD=%s\n' \
        "$(openssl rand -base64 48 | tr -d '\n')" "${ch}" > "${DIR}/api.env" )
    echo "[swetrix] generated SECRET_KEY_BASE and the ClickHouse password -> ${DIR}" >&2
fi
