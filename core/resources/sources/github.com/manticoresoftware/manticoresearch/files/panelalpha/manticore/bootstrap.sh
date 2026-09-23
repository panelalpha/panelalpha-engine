#!/bin/bash
# Creates this account's Manticore admin user, once, inside the daemon's own
# container and as the daemon's own user, and then refuses to leave an open
# instance running. Started in the background by start.sh.
#
# It never exits non-zero: it is a background process, and its exit code goes
# nowhere. What it has instead is the guard at the bottom, which stops the
# daemon rather than reporting anything.
set -u

CONF=/etc/manticoresearch/manticore.conf.sh
AUTH=/var/lib/manticore/auth.json
PIDFILE=/run/manticore/searchd.pid
ENDPOINT=http://127.0.0.1:9308/

log() { echo "[manticore] $*"; }

# The HTTP status of an anonymous request, or empty if nothing answered.
# wget's own exit code is no use: 401 -- the answer this wants -- is a failure
# to it, so the status line is matched instead.
anon_status() {
    wget -q -S -O /dev/null "${ENDPOINT}" 2>&1 \
        | sed -n 's|^[[:space:]]*HTTP/1\.[01][[:space:]]\([0-9][0-9][0-9]\).*|\1|p' \
        | head -1
}

# ---------------------------------------------------------------------------
# 1. Wait for the daemon.
#
# --auth-non-interactive refuses to start without the pid file ("run daemon
# first"), and its reload is a signal to a running process, so waiting for a
# socket that answers is the real gate rather than the file appearing.
ready=
for _ in $(seq 1 180); do
    if [ -s "${PIDFILE}" ] && [ -n "$(anon_status)" ]; then
        ready=1
        break
    fi
    sleep 1
done
if [ -z "${ready}" ]; then
    log "the daemon did not start listening on 9308; not attempting the authentication bootstrap"
    exit 0
fi

# ---------------------------------------------------------------------------
# 2. Create the administrator, unless one is already there.
#
# auth.json is in data_dir, which is the manticore_data volume, so it survives
# the redeploy that empties ~/project. CheckAuthFile() refuses to bootstrap a
# non-empty one anyway; this turns that refusal into one clear line instead of
# a FATAL in the log of every redeploy.
bootstrap() {
    if [ -s "${AUTH}" ] && grep -q '"username"' "${AUTH}" 2>/dev/null; then
        log "this account already has a Manticore login; leaving it alone"
        return
    fi

    local user_name=${MANTICORE_ADMIN_USER:-}
    local user_pass=${MANTICORE_ADMIN_PASSWORD:-}
    if [ -z "${user_name}" ] || [ -z "${user_pass}" ]; then
        log "no credentials in the environment; no administrator can be created"
        return
    fi

    # Three lines on stdin -- login, password, password again -- read by
    # ReadUserCredNonInteractive() in src/auth/auth_bootstrap.cpp. A file
    # rather than a pipe because the reader is a raw read() loop on fd 0 and
    # treats a short read as end of input. Created under umask 077, read once,
    # removed immediately; the same password is already in this process's
    # environment, so it adds no exposure that outlives the next line.
    local tmp
    tmp=$(mktemp) || { log "could not create a temporary file; skipping the bootstrap"; return; }
    chmod 600 "${tmp}"
    printf '%s\n%s\n%s\n' "${user_name}" "${user_pass}" "${user_pass}" > "${tmp}"

    local out rc
    out=$(searchd -c "${CONF}" --auth-non-interactive < "${tmp}" 2>&1)
    rc=$?
    rm -f "${tmp}"

    if [ "${rc}" -eq 0 ]; then
        log "created the administrator login '${user_name}' and reloaded authentication"
    else
        log "the authentication bootstrap failed (exit ${rc})"
        echo "${out}" | grep -viE '^copyright|^manticore [0-9]' | sed 's/^/[manticore] /'
    fi
}

bootstrap

# ---------------------------------------------------------------------------
# 3. The guard, on every path above.
#
# If an anonymous request is answered with anything but 401, this daemon is
# serving a writable search engine to whoever can reach it -- and what can
# reach it is a public domain. Nothing above is trusted to have prevented
# that: `auth = 1` could have been turned off in the account's env file, or
# MANTICORE_IMAGE pinned to a release from before authentication existed.
#
# The answer is to stop serving. `ready` reports the same condition and fails
# the deploy, but a report leaves the daemon up and the domain answering;
# killing searchd is the only thing available in here that actually closes the
# port. The container then exits, `restart: unless-stopped` brings it back, it
# fails the same check and stops again -- a visible restart loop with nothing
# served, which is the right end of the two ways this can be wrong.
guard=$(anon_status)
if [ "${guard}" != "401" ]; then
    log "FATAL: an anonymous request was answered with '${guard:-no answer}', not 401."
    log "FATAL: this instance is not requiring authentication and will not be left"
    log "FATAL: serving. Stopping searchd. Check searchd_auth=1 in"
    log "FATAL: ~/.panelalpha/manticore/manticore.env and that MANTICORE_IMAGE is a"
    log "FATAL: release that supports authentication."
    kill "$(cat "${PIDFILE}" 2>/dev/null)" 2>/dev/null || true
fi

exit 0
