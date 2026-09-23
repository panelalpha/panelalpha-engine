#!/bin/bash
# Runs as the seafile container's entrypoint, in front of the image's own CMD
# (/sbin/my_init -- /scripts/enterpoint.sh), which it execs at the end.
#
# Two things the compose file cannot state on its own.
set -e

log() { echo "[panelalpha] $*"; }

# 1. The account's public address.
#
# Seafile wants it as a bare hostname plus a scheme (SEAFILE_SERVER_HOSTNAME,
# SEAFILE_SERVER_PROTOCOL); seahub/settings.py reads both from the environment
# on every boot and builds SERVICE_URL and FILE_SERVER_ROOT from them. Neither
# key matches ComposePlaceholders' public-URL pattern -- /(^|_)(URL|ORIGIN|
# ENDPOINT|DOMAIN)$/i, and HOSTNAME is not DOMAIN -- so the engine cannot write
# them. PA_PUBLIC_URL can be written, and is split here.
url="${PA_PUBLIC_URL:-}"
proto=$(printf '%s' "$url" | sed -n 's#^\([A-Za-z][A-Za-z0-9+.-]*\)://.*#\1#p')
host=$(printf '%s' "$url" | sed -e 's#^[A-Za-z][A-Za-z0-9+.-]*://##' -e 's#[/?#].*$##')

case "$host" in
    ''|localhost|127.0.0.1|0.0.0.0)
        # Nothing has said what this instance is called -- no domain on the
        # account yet, so the placeholder was never rewritten. Seafile serves
        # either way; every absolute link it generates (share links, upload
        # endpoints) points at localhost until a domain exists and the project
        # is redeployed.
        log "WARNING: no public domain (PA_PUBLIC_URL='${url}'); share and upload links will point at localhost"
        host=localhost
        proto=http
        ;;
esac
[ -n "$proto" ] || proto=https

export SEAFILE_SERVER_HOSTNAME="$host"
export SEAFILE_SERVER_PROTOCOL="$proto"
log "serving as ${proto}://${host}"

# 2. Seahub's worker count.
#
# setup-seafile-mysql.py generates conf/gunicorn.conf.py with `workers = 5`
# and `threads = 4` -- five preloaded Django 5.2 processes, sized for a
# dedicated server. Inside an account they are most of a gigabyte for nothing,
# and the OOM killer takes the first one that grows. Two is enough for one
# tenant, and both the generator (first boot) and the generated file (every
# boot after) are patched, so neither ordering matters.
workers="${SEAHUB_WORKERS:-2}"
threads="${SEAHUB_THREADS:-2}"
for f in /opt/seafile/seafile-server-*/setup-seafile-mysql.py; do
    [ -f "$f" ] || continue
    sed -i "s/^workers = 5$/workers = ${workers}/; s/^threads = 4$/threads = ${threads}/" "$f"
done
if [ -f /shared/seafile/conf/gunicorn.conf.py ]; then
    sed -i "s/^workers = .*/workers = ${workers}/; s/^threads = .*/threads = ${threads}/" \
        /shared/seafile/conf/gunicorn.conf.py
fi
log "seahub gunicorn: ${workers} workers, ${threads} threads"

# my_init imports the existing environment without overriding it
# (import_envvars(False, False)) and then exports it to
# /etc/container_environment, so what is exported here reaches seahub.
exec /sbin/my_init -- /scripts/enterpoint.sh
