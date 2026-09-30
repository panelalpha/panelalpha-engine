#!/bin/bash
# Generate the database, RabbitMQ and Redis passwords once, outside
# ~/project (wiped every deploy); the datastores keep them in their volumes.
set -e
STORE_DIR="${HOME}/.panelalpha/mayan"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"
if [ ! -f "${STORE_DIR}/mayan.env" ]; then
    DB="$(openssl rand -hex 24)"; MQ="$(openssl rand -hex 24)"; RD="$(openssl rand -hex 24)"
    (
        umask 077
        cat > "${STORE_DIR}/mayan.env" <<ENV
POSTGRES_PASSWORD=${DB}
RABBITMQ_DEFAULT_PASS=${MQ}
MAYAN_REDIS_PASSWORD=${RD}
MAYAN_DATABASES={'default':{'ENGINE':'django.db.backends.postgresql','NAME':'mayan','PASSWORD':'${DB}','USER':'mayan','HOST':'postgresql','CONN_MAX_AGE':0}}
MAYAN_CELERY_BROKER_URL=amqp://mayan:${MQ}@rabbitmq:5672/mayan
MAYAN_CELERY_RESULT_BACKEND=redis://:${RD}@redis:6379/1
MAYAN_LOCK_MANAGER_BACKEND_ARGUMENTS={'redis_url':'redis://:${RD}@redis:6379/2'}
MAYAN_SERVER_SIDE_EVENTS_BACKEND_ARGUMENTS={'url':'redis://:${RD}@redis:6379/3'}
ENV
    )
    echo "[mayan] datastore passwords written to ${STORE_DIR}/mayan.env" >&2
fi
