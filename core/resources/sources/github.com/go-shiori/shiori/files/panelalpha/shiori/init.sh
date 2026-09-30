#!/bin/sh
# One-shot `init`, run before `app` on the same volume. Shiori seeds shiori/gopher
# as owner whenever the account table is empty; this starts a private server on
# loopback, creates the generated owner through the API and deletes shiori/gopher,
# so the public port never opens with the default login in place.
set -u

say() { echo "[panelalpha] shiori init: $*"; }
API=http://127.0.0.1:18080

if [ -z "${SHIORI_ADMIN_USER:-}" ] || [ -z "${SHIORI_ADMIN_PASSWORD:-}" ]; then
    say "no owner credentials in the environment; refusing to start"
    exit 1
fi

SHIORI_HTTP_ADDRESS=127.0.0.1: SHIORI_HTTP_PORT=18080 /usr/bin/shiori server &
PID=$!
trap 'kill ${PID} 2>/dev/null; wait ${PID} 2>/dev/null' EXIT

up=0
for i in $(seq 1 60); do
    kill -0 ${PID} 2>/dev/null || { say "shiori exited during start"; exit 1; }
    curl -fsS -o /dev/null "${API}/system/liveness" 2>/dev/null && { up=1; break; }
    sleep 1
done
[ "${up}" = 1 ] || { say "shiori did not answer on loopback"; exit 1; }

# Prints the session token, or nothing when the credentials are refused.
login() {
    jq -nc --arg u "$1" --arg p "$2" '{username:$u,password:$p}' \
        | curl -sS -X POST -H 'Content-Type: application/json' --data @- "${API}/api/v1/auth/login" \
        | jq -r '.message.token // empty'
}

DEFAULT_TOKEN="$(login shiori gopher)"
if [ -z "${DEFAULT_TOKEN}" ]; then
    say "default shiori/gopher login is refused; nothing to do"
    exit 0
fi

# 201 created, 409 already there (then its password must still match below).
code="$(jq -nc --arg u "${SHIORI_ADMIN_USER}" --arg p "${SHIORI_ADMIN_PASSWORD}" '{username:$u,password:$p,owner:true}' \
    | curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -H "Authorization: Bearer ${DEFAULT_TOKEN}" --data @- "${API}/api/v1/accounts")"
say "create owner '${SHIORI_ADMIN_USER}': HTTP ${code}"

ADMIN_TOKEN="$(login "${SHIORI_ADMIN_USER}" "${SHIORI_ADMIN_PASSWORD}")"
[ -n "${ADMIN_TOKEN}" ] || { say "cannot log in as '${SHIORI_ADMIN_USER}'"; exit 1; }

ID="$(curl -sS -H "Authorization: Bearer ${ADMIN_TOKEN}" "${API}/api/v1/accounts" \
    | jq -r '.message[] | select(.username == "shiori") | .id')"
[ -n "${ID}" ] || { say "default account not listed"; exit 1; }
code="$(curl -sS -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer ${ADMIN_TOKEN}" "${API}/api/v1/accounts/${ID}")"
say "delete default account shiori (id ${ID}): HTTP ${code}"

if [ -n "$(login shiori gopher)" ]; then
    say "shiori/gopher still logs in; refusing to start"
    exit 1
fi
say "owner '${SHIORI_ADMIN_USER}' ready, shiori/gopher removed"
