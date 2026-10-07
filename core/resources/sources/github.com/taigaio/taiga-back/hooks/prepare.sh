#!/bin/bash
# Generate Taiga's secret key and the database/RabbitMQ passwords once, outside
# ~/project (wiped every deploy): they must keep matching the data volumes.
set -e
STORE_DIR="${HOME}/.panelalpha/taiga"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/back.env" ]; then
    PG="$(openssl rand -hex 24)"
    RMQ="$(openssl rand -hex 24)"
    KEY="$(openssl rand -hex 32)"
    COOKIE="$(openssl rand -hex 24)"
    (
        umask 077
        echo "POSTGRES_PASSWORD=${PG}" > "${STORE_DIR}/db.env"
        # POSTGRES_* live here, not in the compose file.
        cat > "${STORE_DIR}/back.env" <<ENV
POSTGRES_DB=taiga
POSTGRES_USER=taiga
POSTGRES_PASSWORD=${PG}
POSTGRES_HOST=taiga-db
TAIGA_SECRET_KEY=${KEY}
RABBITMQ_USER=taiga
RABBITMQ_PASS=${RMQ}
ENV
        cat > "${STORE_DIR}/rabbitmq.env" <<ENV
RABBITMQ_DEFAULT_USER=taiga
RABBITMQ_DEFAULT_PASS=${RMQ}
RABBITMQ_DEFAULT_VHOST=taiga
RABBITMQ_ERLANG_COOKIE=${COOKIE}
ENV
        cat > "${STORE_DIR}/events.env" <<ENV
RABBITMQ_USER=taiga
RABBITMQ_PASS=${RMQ}
TAIGA_SECRET_KEY=${KEY}
ENV
        echo "SECRET_KEY=${KEY}" > "${STORE_DIR}/protected.env"
    )
    echo "[taiga] secrets written to ${STORE_DIR}" >&2
fi
