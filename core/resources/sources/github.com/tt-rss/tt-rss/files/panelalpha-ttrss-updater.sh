#!/bin/sh
# Runs inside the app container on the start stage, before the entrypoint execs
# Apache. Returns immediately; the daemon it leaves behind is what makes a
# subscribed feed actually fetch anything.
#
# Nothing in tt-rss's web interface updates a feed. Feeds::subscribe_to_feed
# fetches once to resolve the title, and after that every headline comes from
# update.php: `--daemon` is upstream's single-process updater, the one
# .docker/app/updater.sh runs as its own container. There is no container to
# put it in here -- the php strategy generates one service and the recipe
# cannot name the image compose gave it -- so it runs beside Apache in the app
# container instead. That is how a hand-installed tt-rss has always worked,
# except with cron rather than a daemon.
#
# The wrapping loop is the supervision compose would otherwise give it: the
# daemon exits when it cannot get its lock or when the database goes away, and
# without this a single blip would leave an account whose feeds silently stop
# updating until someone restarts the container.
#
# --quiet keeps per-feed chatter out of the container log; errors still go to
# stderr and land in `docker logs`.
set -e

if [ -f /app/lock/update_daemon.lock ]; then
    # Left behind by a container that was killed rather than stopped. The
    # daemon takes an flock, so a stale file is harmless -- said out loud
    # because the alternative reading is that another updater is running.
    echo "[tt-rss] stale lock file present; the daemon's flock decides" >&2
fi

# stdout to /dev/null; stderr deliberately left alone. --quiet already silences
# the per-feed progress, and what would still reach stdout is a periodic banner
# nobody needs interleaved with Apache's access log. Errors -- a feed that will
# not parse, a database that went away, the daemon exiting -- go to stderr,
# which is the container's, so they show up in `docker logs` and in the panel's
# log view where an operator will actually see them.
nohup sh -c '
    while true; do
        php /app/update.php --daemon --quiet || true
        sleep 30
    done
' >/dev/null &

echo "[tt-rss] feed updater started (update.php --daemon)" >&2
