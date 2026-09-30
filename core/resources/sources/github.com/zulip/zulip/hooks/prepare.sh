#!/bin/bash
# Generate the datastore passwords and Django's secret key once, outside
# ~/project (wiped every deploy); the datastores keep them in their volumes.
set -e
STORE_DIR="${HOME}/.panelalpha/zulip"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/zulip.env" ]; then
    PG="$(openssl rand -hex 24)"; MC="$(openssl rand -hex 24)"
    MQ="$(openssl rand -hex 24)"; RD="$(openssl rand -hex 24)"
    SK="$(openssl rand -hex 48)"
    (
        umask 077
        # SECRETS_* land in /etc/zulip/zulip-secrets.conf (docker-zulip).
        cat > "${STORE_DIR}/zulip.env" <<ENV
POSTGRES_PASSWORD=${PG}
MEMCACHED_PASSWORD=${MC}
RABBITMQ_DEFAULT_PASS=${MQ}
REDIS_PASSWORD=${RD}
SECRETS_postgres_password=${PG}
SECRETS_memcached_password=${MC}
SECRETS_rabbitmq_password=${MQ}
SECRETS_redis_password=${RD}
SECRETS_secret_key=${SK}
ENV
    )
    echo "[zulip] secrets written to ${STORE_DIR}/zulip.env" >&2
fi
