#!/bin/sh
# The web process. Derives the account's own configuration, then execs the
# image's own CMD with one change: worker count.
set -e
# shellcheck disable=SC1091
. /app/panelalpha/saleor/env.sh

# The image's CMD is --workers=2 (Dockerfile, last line), sized for a machine
# rather than for one hosting account. Each uvicorn worker is a full Django
# process with Saleor's whole dependency tree resident, so the second one costs
# about as much as the database and the broker together. One worker, and the
# figure is overridable from the account's env_vars for an operator who has the
# room.
exec uvicorn saleor.asgi:application \
    --host=0.0.0.0 --port=8000 \
    --workers="${SALEOR_WEB_WORKERS:-1}" \
    --lifespan=auto --ws=none --no-server-header --no-access-log \
    --timeout-keep-alive=35 --timeout-graceful-shutdown=30 \
    --limit-max-requests=10000
