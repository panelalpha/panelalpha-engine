#!/bin/sh
# One-shot: complete re:Director's first-run /setup so the admin exists before
# the site is ever published. Idempotent -- if a user already exists it does
# nothing. `ready` waits on this, so the engine returns from `up` (and starts
# routing public traffic) only after the admin has been created; the setup
# window is therefore never reachable from outside.
set -eu

BASE="http://app:80"
USER="${RD_ADMIN_USERNAME:-admin}"
PASS="${RD_ADMIN_PASSWORD:?admin password not provided}"
JAR="$(mktemp)"
PAGE="$(mktemp)"

log() { echo "[re-director/seed] $*"; }

# Wait for the app to answer. `/` is permitAll and returns 200 directly (no
# redirect for the internal Host), so a plain success means the app is serving.
i=0
until curl -fsS -o /dev/null "${BASE}/" 2>/dev/null; do
    i=$((i + 1))
    if [ "${i}" -ge 90 ]; then
        log "app never answered on ${BASE}/"
        exit 1
    fi
    sleep 2
done

# GET /setup: 200 => no user yet (the form is shown); 302 => a user exists and
# it redirects to /login. Capture the session cookie in the jar at the same time.
code="$(curl -s -o "${PAGE}" -w '%{http_code}' -c "${JAR}" "${BASE}/setup")"
if [ "${code}" != "200" ]; then
    log "an admin already exists (/setup -> ${code}); leaving it untouched"
    exit 0
fi

# Pull the CSRF token out of the hidden field; the POST needs it plus the cookie.
token="$(sed -n 's/.*name="_csrf"[^>]*value="\([^"]*\)".*/\1/p' "${PAGE}" | head -n1)"
if [ -z "${token}" ]; then
    log "could not find the CSRF token on /setup"
    exit 1
fi

log "creating admin '${USER}'"
post="$(curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" -c "${JAR}" \
    --data-urlencode "username=${USER}" \
    --data-urlencode "password=${PASS}" \
    --data-urlencode "confirmPassword=${PASS}" \
    --data-urlencode "_csrf=${token}" \
    "${BASE}/setup")"

# handleSetup returns redirect:/login (302/303) on success. Confirm by checking
# that /setup now redirects instead of showing the form.
verify="$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/setup")"
if [ "${verify}" = "302" ] || [ "${verify}" = "303" ]; then
    log "admin '${USER}' created (setup POST ${post})"
    exit 0
fi

log "setup did not take (POST ${post}, /setup still ${verify})"
exit 1
