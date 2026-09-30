#!/bin/bash
# Generates the auth secret and the PostgreSQL/ClickHouse/Redis passwords once
# into ~/.panelalpha/rybbit; the volumes keep the first values.
set -e
DIR="${HOME}/.panelalpha/rybbit"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/rybbit.env" ]; then
    ch="$(openssl rand -hex 24)"
    ( umask 077
      printf 'BETTER_AUTH_SECRET=%s\nPOSTGRES_PASSWORD=%s\nCLICKHOUSE_PASSWORD=%s\nCLICKHOUSE_QUERY_PASSWORD=%s\nREDIS_PASSWORD=%s\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 24)" "${ch}" "${ch}" "$(openssl rand -hex 24)" > "${DIR}/rybbit.env" )
    echo "[rybbit] generated the auth secret and service passwords -> ${DIR}" >&2
fi
chmod 600 "${DIR}/rybbit.env"
