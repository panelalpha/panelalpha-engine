#!/bin/bash
# Generate the database password, APP_SECRET and JWT_PASSPHRASE once, outside
# ~/project (wiped on every deploy); the postgres volume outlives the checkout.
set -e
DIR="${HOME}/.panelalpha/domain-watchdog"
FILE="${DIR}/secrets.env"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${FILE}" ]; then
    PW=$(openssl rand -hex 24)
    (
        umask 077
        cat > "${FILE}" <<EOT
POSTGRES_PASSWORD=${PW}
DATABASE_URL=postgresql://app:${PW}@database:5432/app?serverVersion=16&charset=utf8
APP_SECRET=$(openssl rand -hex 32)
JWT_PASSPHRASE=$(openssl rand -hex 32)
EOT
    )
fi
chmod 600 "${FILE}"
