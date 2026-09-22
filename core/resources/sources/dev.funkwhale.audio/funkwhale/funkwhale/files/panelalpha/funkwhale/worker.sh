#!/bin/sh
# Celery worker. Processes an uploaded track's import, federation delivery and
# other background tasks. --concurrency=1: each child is a full Django process;
# the default is one per CPU, far more than one account needs.
set -e
exec celery \
    --app=funkwhale_api.taskapp \
    worker \
    --loglevel="${CELERY_LOGLEVEL:-INFO}" \
    --concurrency="${CELERYD_CONCURRENCY:-1}" \
    --without-gossip --without-mingle
