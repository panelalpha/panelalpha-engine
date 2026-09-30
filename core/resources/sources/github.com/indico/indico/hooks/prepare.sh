#!/bin/bash
# Generate the database password and Indico's SECRET_KEY once, outside
# ~/project (wiped every deploy): sessions and signed links depend on them.
set -e
DIR="${HOME}/.panelalpha/indico"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/indico.env" ]; then
    pw="$(openssl rand -hex 24)"
    ( umask 077; cat > "${DIR}/indico.env" <<ENV
PGHOST=indico-postgres
PGUSER=indico
PGDATABASE=indico
PGPASSWORD=${pw}
POSTGRES_USER=indico
POSTGRES_DB=indico
POSTGRES_PASSWORD=${pw}
INDICO_SECRET_KEY=$(openssl rand -hex 32)
ENV
    )
    echo "[indico] generated the database password and SECRET_KEY -> ${DIR}/indico.env" >&2
fi
chmod 600 "${DIR}/indico.env"
