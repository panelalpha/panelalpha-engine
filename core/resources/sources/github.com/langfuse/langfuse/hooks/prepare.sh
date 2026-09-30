#!/bin/bash
# Generates the datastore passwords and app secrets once, in ~/.panelalpha
# (survives redeploys; ~/project does not). Replaces upstream's CHANGEME defaults.
set -e

STORE="${HOME}/.panelalpha/langfuse"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    PG=$(openssl rand -hex 16); CH=$(openssl rand -hex 16)
    RD=$(openssl rand -hex 16); MN=$(openssl rand -hex 16)
    (umask 077
     printf 'POSTGRES_PASSWORD=%s\n' "${PG}" > "${STORE}/db.env"
     printf 'CLICKHOUSE_PASSWORD=%s\n' "${CH}" > "${STORE}/clickhouse.env"
     printf 'REDIS_AUTH=%s\n' "${RD}" > "${STORE}/redis.env"
     printf 'MINIO_ROOT_PASSWORD=%s\n' "${MN}" > "${STORE}/minio.env"
     {
       printf 'DATABASE_URL=postgresql://postgres:%s@postgres:5432/postgres\n' "${PG}"
       printf 'CLICKHOUSE_PASSWORD=%s\nREDIS_AUTH=%s\n' "${CH}" "${RD}"
       printf 'LANGFUSE_S3_EVENT_UPLOAD_SECRET_ACCESS_KEY=%s\n' "${MN}"
       printf 'NEXTAUTH_SECRET=%s\nSALT=%s\nENCRYPTION_KEY=%s\n' \
           "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)"
     } > "${STORE}/app.env")
fi
chmod 600 "${STORE}"/*.env
