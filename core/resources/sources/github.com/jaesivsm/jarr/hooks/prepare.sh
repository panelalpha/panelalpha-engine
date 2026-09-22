#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: persist this account's secrets where the next deploy will not delete
# them, and materialise /etc/jarr/jarr.json (with those secrets and the compose
# service hostnames) from them on every deploy.
set -e
cd ~/project

say() { echo "[jarr] $*" >&2; }

# ~/.panelalpha/jarr/ survives; ~/project is emptied on every deploy
# (ProjectTree::clearContents), so a secret written there is regenerated every
# rebuild -- a new SECRET_KEY logs everyone out (JWTs invalid), and a new DB
# password locks the app out of the pgdata volume that still holds the old one.
STORE="${HOME}/.panelalpha/jarr"
SECRETS="${STORE}/secrets.env"   # generated once, sourced every deploy
DB_ENV="${STORE}/db.env"         # read by the postgres container's first boot
APP_ENV="${STORE}/app.env"       # admin creds, injected into the init container
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRETS}" ] || [ ! -f "${DB_ENV}" ] || [ ! -f "${APP_ENV}" ]; then
    # hex only: embedded in a JSON DSN and read back from an env file, so no
    # '/', '+', '@', '=' or quotes to confuse either.
    PG_PASSWORD="$(openssl rand -hex 24)"
    SECRET_KEY="$(openssl rand -hex 32)"
    ADMIN_LOGIN="admin"
    ADMIN_PASSWORD="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${SECRETS}" <<EOF
# Written once by PanelAlpha on the first deploy and never regenerated. Deleting
# this file strands the pgdata volume (old password) and logs every session out.
PG_PASSWORD=${PG_PASSWORD}
SECRET_KEY=${SECRET_KEY}
ADMIN_LOGIN=${ADMIN_LOGIN}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its FIRST boot to set the role's password,
# and by nothing else. Same value as PG_PASSWORD; never regenerated because it
# is baked into the pgdata volume.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Admin credentials seeded once by the init one-shot. NOT JARR config keys
# (the_conf ignores them); read straight from the environment by init.sh.
JARR_ADMIN_LOGIN=${ADMIN_LOGIN}
JARR_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    )
    say "secrets written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# shellcheck disable=SC1090
. "${SECRETS}"

# Materialise the JARR config each deploy from the persisted secrets. This is the
# file JARR reads by default (jarr/metaconf.yml -> /etc/jarr/jarr.json) and the
# compose mounts it read-only into server, worker, init and the scheduler kick.
#  * db.pg_uri points at the `db` service with the persisted password
#  * Celery uses Redis as broker (db 2) and backend (db 0); JARR's own cache is
#    Redis db 1. Redis broker avoids RabbitMQ, whose default guest user cannot
#    connect from another container anyway.
#  * the seeded admin's credentials go in app.env (env, not JARR config), because
#    the current metaconf has no crawler.login/passwd keys.
#  * log.level 30 (WARNING) keeps Flask out of debug mode (debug is on only when
#    level <= 10).
#  * api.scheme https so generated URLs use https behind the engine's TLS proxy.
CONF_DIR="${HOME}/project/panelalpha/jarr"
mkdir -p "${CONF_DIR}"
cat > "${CONF_DIR}/jarr.json" <<EOF
{
    "jarr_testing": false,
    "log": {"level": 30},
    "db": {
        "pg_uri": "postgresql://jarr:${PG_PASSWORD}@db:5432/jarr",
        "redis": {"host": "redis", "db": 1, "port": 6379}
    },
    "celery": {
        "broker": "redis://redis:6379/2",
        "broker_url": "redis://redis:6379/2",
        "backend": "redis://redis:6379/0"
    },
    "auth": {"secret_key": "${SECRET_KEY}", "allow_signup": false},
    "api": {"scheme": "https"}
}
EOF
# World-readable on purpose: this file is bind-mounted into the app containers,
# which run as their image's own non-root user (a different UID than the account
# owner), so 600 would be unreadable there and the_conf would fail to load. The
# secrets it holds stay inside this single-tenant account's own containers.
chmod 644 "${CONF_DIR}/jarr.json"

cat > "${NOTE}" <<EOF
JARR on this account
====================

JARR (Just Another RSS Reader) is a multi-user web RSS reader. Each visitor
logs into their own account and sees only their own feeds and articles.

ADMIN LOGIN (seeded once, on the first deploy)
  URL:      this account's URL (log in from the front page)
  Username: ${ADMIN_LOGIN}
  Password: ${ADMIN_PASSWORD}

  Change it from the account settings after first login; a redeploy will not
  reset it (the init one-shot only seeds the admin when it is missing).

REGISTRATION
  Account signup is open: anyone reaching the instance can create their own
  account. Every feed/article endpoint is login-scoped, so a new account sees
  none of another user's data. Front the instance with your own access control
  if you need a closed instance.

FEED CRAWLING
  A background Celery worker crawls subscribed feeds. A self-rescheduling
  scheduler is kicked once at deploy and then re-queues itself roughly every
  two minutes, so a newly added feed is fetched within a couple of minutes.

SECRETS
  SECRET_KEY and the database password live in ${STORE} (0600). They are
  generated once and reused on every redeploy, which keeps logins and the
  database working across rebuilds. Do not delete this directory.

EMAIL (optional)
  Password recovery emails need SMTP, which is not configured by default. Set
  the notification.* keys in the JARR config to enable them.
EOF

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written it yet, so `docker compose up` does not abort.
touch .env
say "prepare complete"
