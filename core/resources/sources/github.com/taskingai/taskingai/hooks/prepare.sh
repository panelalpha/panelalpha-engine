#!/bin/bash
# Keys and database passwords generated once: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password and AES_ENCRYPTION_KEY encrypts
# stored provider credentials.
set -e
STORE="${HOME}/.panelalpha/taskingai"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/taskingai.env" ]; then
    (
        umask 077
        pg=$(openssl rand -hex 24)
        redis=$(openssl rand -hex 24)
        {
            printf 'AES_ENCRYPTION_KEY=%s\n' "$(openssl rand -hex 32)"
            printf 'JWT_SECRET_KEY=%s\n' "$(openssl rand -hex 32)"
            printf 'POSTGRES_DB=taskingai\nPOSTGRES_USER=postgres\nPOSTGRES_PASSWORD=%s\n' "${pg}"
            printf 'POSTGRES_URL=postgres://postgres:%s@db:5432/taskingai\n' "${pg}"
            printf 'REDIS_PASSWORD=%s\nREDIS_URL=redis://:%s@cache:6379/0\n' "${redis}" "${redis}"
            # Upstream's .env.example defaults, unchanged.
            printf 'DEFAULT_ADMIN_USERNAME=admin\nDEFAULT_ADMIN_PASSWORD=TaskingAI321\n'
        } > "${STORE}/taskingai.env"
    )
fi
chmod 600 "${STORE}/taskingai.env"
install -m 600 "${STORE}/taskingai.env" ~/project/taskingai.env
