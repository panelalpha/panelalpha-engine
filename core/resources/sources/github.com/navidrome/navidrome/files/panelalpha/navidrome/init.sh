#!/bin/sh
# One-shot `init`, run before `app` on the same volumes. On an empty database
# Navidrome's web UI offers "Create Admin User" to whoever arrives first; this
# starts a private server on loopback and creates the admin through that same
# endpoint (/auth/createAdmin), so the public port never opens in first-run state.
set -u

say() { echo "[panelalpha] navidrome init: $*"; }
API=http://127.0.0.1:14533

if [ -z "${NAVIDROME_ADMIN_USER:-}" ] || [ -z "${NAVIDROME_ADMIN_PASSWORD:-}" ]; then
    say "no admin credentials in the environment; refusing to start"
    exit 1
fi

ND_ADDRESS=127.0.0.1 ND_PORT=14533 ND_SCANNER_ENABLED=false ND_SCANNER_SCANONSTARTUP=false \
    ND_ENABLEINSIGHTSCOLLECTOR=false \
    /app/navidrome &
PID=$!
# The post-migration scan is cut short on exit ("context canceled" errors);
# `app` runs its own full scan when it starts.
trap 'kill ${PID} 2>/dev/null; wait ${PID} 2>/dev/null' EXIT

up=0
for i in $(seq 1 90); do
    kill -0 ${PID} 2>/dev/null || { say "navidrome exited during start"; exit 1; }
    curl -fsS -o /dev/null "${API}/ping" 2>/dev/null && { up=1; break; }
    sleep 1
done
[ "${up}" = 1 ] || { say "navidrome did not answer on loopback"; exit 1; }

BODY="$(printf '{"username":"%s","password":"%s"}' "${NAVIDROME_ADMIN_USER}" "${NAVIDROME_ADMIN_PASSWORD}")"
# 200 created; 403 "Cannot create another first admin" once any user exists.
code="$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data "${BODY}" "${API}/auth/createAdmin")"
case "${code}" in
    200)
        code="$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
            --data "${BODY}" "${API}/auth/login")"
        [ "${code}" = 200 ] || { say "admin created but login returned HTTP ${code}"; exit 1; }
        say "admin '${NAVIDROME_ADMIN_USER}' created and logs in" ;;
    403)
        say "users already exist; nothing to do" ;;
    *)
        say "createAdmin returned HTTP ${code}"; exit 1 ;;
esac
