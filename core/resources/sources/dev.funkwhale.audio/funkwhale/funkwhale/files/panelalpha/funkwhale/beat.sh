#!/bin/sh
# Celery beat, the periodic scheduler. The schedule file goes in /tmp: beat
# rewrites it on every tick and /app is read-only to this container.
set -e
exec celery \
    --app=funkwhale_api.taskapp \
    beat \
    --loglevel="${CELERY_LOGLEVEL:-INFO}" \
    --schedule /tmp/celerybeat-schedule
