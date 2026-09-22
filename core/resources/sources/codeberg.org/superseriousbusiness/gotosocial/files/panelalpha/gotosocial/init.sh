#!/bin/sh
# Entrypoint of the one-shot `init` service. Runs before `app` is allowed to
# start (app declares `init: service_completed_successfully`), so it has to exit
# 0 or there is no site.
#
# GoToSocial will not create a user until the "instance application" exists, and
# that is only created the first time `gotosocial server start` runs
# ("NewSignup: instance application not yet created, run the server at least once
# *before* creating users"). So this one-shot starts an INTERNAL server -- this
# container publishes no ports, so nothing is reachable from outside -- waits for
# it to bootstrap and answer /readyz, stops it, and only then creates the
# administrator. The public port opens only when `app` starts, which is after
# this service has completed, so the instance is never reachable adminless
# (engine#200). Idempotent: on a redeploy the administrator already exists and
# nothing is created or reset.
set -e

say() { echo "[panelalpha] gotosocial init: $*" >&2; }

GTS=/gotosocial/gotosocial

# Same host derivation as entrypoint.sh, so the administrator's account URIs and
# the instance identity are minted against the real public domain. Fail closed.
url="${PA_PUBLIC_URL:-}"
host="${url#*://}"; host="${host%%/*}"; host="${host%%:*}"
if [ -z "${host}" ] || [ "${host}" = "localhost" ] || [ "${host}" = "127.0.0.1" ]; then
    say "no public host resolved from PA_PUBLIC_URL='${url}'; refusing to bootstrap a placeholder identity"
    exit 1
fi
export GTS_HOST="${host}"
say "GTS_HOST=${GTS_HOST}"

USER_NAME="${PA_ADMIN_USER:-admin}"
USER_EMAIL="${PA_ADMIN_EMAIL:-admin@localhost}"
USER_PASSWORD="${PA_ADMIN_PASSWORD:-}"

# GoToSocial's CLI writes its INFO logs to stdout, which would swamp the table
# `admin account list` prints. At error level the logs go quiet and the table is
# the only thing on stdout, so the existence check below is reliable.
export GTS_LOG_LEVEL=error

# True when a local account with this username already exists. `admin account
# list` prints a header row then one row per account; column 1 is the username.
exists() {
    "${GTS}" admin account list 2>/dev/null | awk 'NR>1 {print $1}' | grep -qx "${USER_NAME}"
}

if exists; then
    say "administrator '${USER_NAME}' already exists; leaving it and its password alone"
    say "done"
    exit 0
fi

if [ -z "${USER_PASSWORD}" ]; then
    say "no administrator password in the environment; refusing to leave the instance adminless"
    exit 1
fi

# ---------------------------------------------------------------------------
# Bootstrap the instance application by running the server once. This container
# maps no ports, so the server is reachable only on the compose network for the
# few seconds it runs here; the public 8080 belongs to `app`, which has not
# started yet.
say "starting internal server to bootstrap the instance application"
"${GTS}" server start &
SRV=$!

ready=0
i=0
while [ ${i} -lt 90 ]; do
    if wget -q -O - http://127.0.0.1:8080/readyz >/dev/null 2>&1; then
        ready=1
        break
    fi
    # Stop waiting if the server process has died.
    kill -0 "${SRV}" 2>/dev/null || break
    i=$((i + 1))
    sleep 1
done

# Stop the internal server before touching the database from a second process --
# the instance application it created persists, so the admin can be made against
# the now-idle database with no SQLite write contention.
say "stopping internal server (ready=${ready})"
kill "${SRV}" 2>/dev/null || true
wait "${SRV}" 2>/dev/null || true

if [ "${ready}" != "1" ]; then
    say "internal server did not become ready; cannot create the administrator"
    exit 1
fi

say "creating administrator '${USER_NAME}'"
"${GTS}" admin account create --username "${USER_NAME}" --email "${USER_EMAIL}" --password "${USER_PASSWORD}"
say "confirming '${USER_NAME}'"
"${GTS}" admin account confirm --username "${USER_NAME}"
say "promoting '${USER_NAME}' to admin"
"${GTS}" admin account promote --username "${USER_NAME}"

# The only thing standing between the instance and an adminless moderation
# surface is this account, so a create that silently did nothing must fail.
if exists; then
    say "administrator '${USER_NAME}' created"
    say "done"
else
    say "administrator was not created; stopping"
    exit 1
fi
