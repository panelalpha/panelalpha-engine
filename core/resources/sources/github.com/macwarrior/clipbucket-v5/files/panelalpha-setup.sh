#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Idempotent: the upgrade stage replays it on every redeploy.
#
# This file and panelalpha-install.php sit at the repository root, which is
# *not* the document root -- ClipBucket serves from upload/ -- so neither is
# reachable over HTTP. That is why they are not in a panelalpha/ subdirectory
# and do not need the vhost's `panelalpha-*` deny rule to save them, though it
# would cover them if the document root ever moved.
set -e
cd /app

log() { echo "[clipbucket] $*"; }

# `check` and `version` print shell assignments and nothing else -- but the
# output is eval'd, so the filter is not a formality: anything else reaching
# stdout would be run by this shell. panelalpha-install.php puts all of PHP's
# own output on stderr for the same reason.
cb_check() {
    php /app/panelalpha-install.php check | grep -E '^(installed|schema|admin)=[01]$'
}

cb_version() {
    php /app/panelalpha-install.php version | grep -E '^(schema|code)=' | sed 's/^/cb_/'
}

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    log "no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# The tools, checked here rather than discovered later. ClipBucket resolves them
# through config rows that the seed writes, and a missing binary would not show
# up until the first upload failed silently in a background process. /data is
# ~/.panelalpha/clipbucket, mounted by the compose override; hooks/prepare.sh
# fills it.
for tool in ffmpeg ffprobe; do
    if [ ! -x "/data/bin/${tool}" ]; then
        log "/data/bin/${tool} is missing. ClipBucket cannot convert an uploaded video" >&2
        log "without it, and upstream's own installer refuses to run without it." >&2
        log "hooks/prepare.sh fetches it into ~/.panelalpha/clipbucket/bin." >&2
        exit 1
    fi
done
if [ ! -x /data/bin/mediainfo ]; then
    log "note: /data/bin/mediainfo is missing. ffprobe covers everything except the"
    log "duration fallback and anamorphic Original width/height; see README.md."
fi

# The uploaded media has to be on the mount, not in the checkout. If this is the
# checkout's own directory the bind mount did not happen, and every video this
# account uploads would be deleted by the next deploy while its database row
# survived. Better to fail the deploy than to publish that.
if ! mountpoint -q /app/upload/files 2>/dev/null; then
    # mountpoint is not in the base image on every tag; fall back to the file
    # only the mount can have.
    if [ ! -d /data/files ]; then
        log "/app/upload/files is not the bind mount and /data/files does not exist;" >&2
        log "check the volumes in overrides/docker-compose.override.yml (engine#173)." >&2
        exit 1
    fi
fi

# 1. upload/includes/config.php -- the database the engine provisioned. Written
#    every time rather than only when missing: every deploy re-clones over
#    ~/project (engine#173), so there is never a file here to preserve, and the
#    values the container was handed are by definition the ones that work.
php /app/panelalpha-install.php config

# 2. The installation itself.
#
#    ClipBucket's web installer is not something to leave to a visitor: it is
#    gated only on upload/files/temp/install.me, which is committed to the
#    repository, and cb_install/ajax.php's `create_files` step writes
#    upload/includes/config.php by string-substituting POST data into a PHP
#    file with no escaping -- unauthenticated remote code execution, live from
#    the first request. hooks/prepare.sh deletes the lock file before anything
#    is built; this is what then installs the application.
eval "$(cb_check)"

if [ "${installed}" = "1" ]; then
    log "already installed; leaving the data alone"

    # 3. A version bump in the checkout.
    #
    #    ClipBucket migrates through cb_install/sql/<version>/M*.php, driven by
    #    AdminTool's `update_database_version` tool -- which is runnable from
    #    the CLI (admin_area/actions/tool_launch.php takes `id_tool=` and runs
    #    the tool synchronously when php_sapi_name() is cli). So unlike ZenTao
    #    the upgrade path is automatable, and it runs here rather than being
    #    left to whoever visits the admin area first and sees the banner.
    #
    #    The seed's settings are replayed first, because the account's public
    #    URL and the tool paths can change between deploys and base_url is what
    #    every absolute link on the site is built from
    #    (Network::get_server_url(), network.class.php:303).
    php /app/panelalpha-install.php seed

    eval "$(cb_version)"
    if [ "${cb_schema}" != "${cb_code}" ]; then
        log "schema ${cb_schema}, checkout ${cb_code}: running ClipBucket's own migrations"
        php /app/panelalpha-migrate.php
        eval "$(cb_version)"
        if [ "${cb_schema}" != "${cb_code}" ]; then
            log "the migrations ran but the schema is still ${cb_schema} against a" >&2
            log "${cb_code} checkout. Refusing to serve an application whose code and" >&2
            log "schema disagree; the previous container keeps running." >&2
            exit 1
        fi
    fi
    log "schema ${cb_schema} matches the checkout"
    exit 0
fi

log "installing ClipBucket"

# Two processes, and not for tidiness. `schema` speaks mysqli directly because
# there is no application to bootstrap -- includes/common.php connects and reads
# cb_config on load, and cb_config is what the first phase creates. `seed` needs
# the opposite: upstream's own pass_code() and Migration::updateConfig(), which
# only exist once the application has started.
#
# The schema phase is skipped when it has already run, and that guard is not
# decoration: cb_install/sql/structure.sql is plain `CREATE TABLE`, with no
# IF NOT EXISTS anywhere in it, so a second pass stops on "Table
# 'cb_action_log' already exists". The engine retries a failed install stage,
# so without this an installation that imported 2 MB of schema and then died in
# the seed could never be finished -- every retry would fail on the first
# statement and the account would be stuck with a database it cannot use.
if [ "${schema}" = "1" ]; then
    log "the schema is already there; resuming at the administrator"
else
    php /app/panelalpha-install.php schema
fi
php /app/panelalpha-install.php seed

eval "$(cb_check)"
if [ "${installed}" != "1" ]; then
    log "the installer reported success but the schema or the administrator is not there" >&2
    exit 1
fi

log "installed; the administrator's password is in ~/.panelalpha/clipbucket/admin-credentials"
