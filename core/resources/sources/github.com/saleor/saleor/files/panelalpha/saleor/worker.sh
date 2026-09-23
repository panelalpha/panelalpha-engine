#!/bin/sh
# Celery worker with beat embedded.
#
# settings.py:643 sets CELERY_TASK_ALWAYS_EAGER whenever CELERY_BROKER_URL is
# empty, which is why Saleor appears to work without this container. It does
# not: eager mode inlines the tasks a *request* dispatches and has nothing to do
# with CELERY_BEAT_SCHEDULE (settings.py:673), whose twenty-odd entries simply
# never run. Two of those are load-bearing rather than housekeeping --
# update-products-search-vectors is the only writer of a product's search
# vector, and recalculate-discounted-price-for-products is the only thing that
# applies a promotion to a price.
#
# -B runs beat inside the worker. Upstream runs them as separate deployments,
# which is right when there are several workers -- two beats would double every
# scheduled task. There is exactly one worker in a hosting account and there
# cannot be a second (nothing here scales out), so one process instead of two
# saves a whole Django interpreter, about a third of the account's budget.
set -e
# shellcheck disable=SC1091
. /app/panelalpha/saleor/env.sh

cd /app
# --concurrency=1: each child is another full Django process. The default is one
# per CPU, which on this host is four.
# --without-gossip/--without-mingle: both are worker-to-worker chatter with no
# second worker to talk to.
# The schedule file goes in /tmp, not the code tree: beat writes it on every
# tick and ~/project is read-only as far as this container is concerned.
exec celery --app saleor.celeryconf:app worker \
    --beat \
    --schedule /tmp/celerybeat-schedule \
    --concurrency="${SALEOR_WORKER_CONCURRENCY:-1}" \
    --without-gossip --without-mingle \
    --loglevel="${SALEOR_WORKER_LOGLEVEL:-info}"
