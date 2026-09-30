#!/bin/sh
# One-shot `seed`, run before `app` on the same volume. Registration is closed
# on the public app, so the first user is created here, on a backend that is
# never published, and only while users.json holds no user.
set -u

say() { echo "[panelalpha] dailytxt seed: $*"; }
API=http://127.0.0.1:8000/api
USERS=/data/users.json

if [ -z "${DAILYTXT_USER:-}" ] || [ -z "${DAILYTXT_PASSWORD:-}" ]; then
    say "no user credentials in the environment; refusing to start"
    exit 1
fi

if [ -s "${USERS}" ] && grep -q '"username"' "${USERS}"; then
    say "users.json already has a user; nothing to do"
    exit 0
fi

# The backend reads ./version, so it runs from /.
cd / && ALLOW_REGISTRATION=true /usr/local/bin/dailytxt > /tmp/seed-backend.log 2>&1 &
PID=$!
trap 'kill ${PID} 2>/dev/null; wait ${PID} 2>/dev/null' EXIT

up=0
for i in $(seq 1 60); do
    kill -0 ${PID} 2>/dev/null || { say "backend exited during start"; exit 1; }
    wget -q -O /dev/null "${API}/version" 2>/dev/null && { up=1; break; }
    sleep 1
done
[ "${up}" = 1 ] || { say "backend did not answer on loopback"; exit 1; }

BODY="{\"username\":\"${DAILYTXT_USER}\",\"password\":\"${DAILYTXT_PASSWORD}\"}"
out="$(wget -q -O - --header 'Content-Type: application/json' --post-data "${BODY}" "${API}/users/register" 2>&1)" \
    || { say "register failed: ${out}"; exit 1; }
say "register '${DAILYTXT_USER}': ${out}"

wget -q -O /dev/null --header 'Content-Type: application/json' --post-data "${BODY}" "${API}/users/login" \
    || { say "cannot log in as '${DAILYTXT_USER}'"; exit 1; }
say "user '${DAILYTXT_USER}' ready; public registration stays closed"
