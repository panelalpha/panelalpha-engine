#!/bin/sh
# yarnd boot wrapper. Runs (via the image's /init) as the account uid with
# /data bind-mounted to the account's persistent ~/.panelalpha/yarn.
#
#  1. Reuse the persistent production secrets so sessions/tokens survive a
#     restart (the image otherwise regenerates random ones every boot).
#  2. Seed the owner admin through yarnd's gated /setup wizard on localhost,
#     BEFORE the port is publicly reachable, closing the first-visitor-wins
#     window (engine#200). Idempotent: once setup is complete /setup 302s to /.
#  3. Hand the container over to yarnd in the foreground.
set -eu

# 1. Persistent secrets (generated once by hooks/prepare.sh, 0600). These live
# alongside the data in the /data mount, never in ~/project. Sourcing them
# overrides the random values the image's entrypoint exported.
if [ -f /data/secrets.env ]; then
  set -a
  . /data/secrets.env
  set +a
fi

# Pod name defaults to the public host if none was given.
POD_HOST="${BASE_URL#*://}"
POD_HOST="${POD_HOST%%/*}"
: "${NAME:=${POD_HOST:-yarn}}"
export NAME

# 3 (started early so we can seed against it). yarnd reads its config from the
# environment /init prepared plus the secrets sourced above.
yarnd &
YARN_PID=$!
trap 'kill -TERM "$YARN_PID" 2>/dev/null || true' TERM INT

# 2. Seed the admin through yarnd's gated /setup wizard.
#
# /setup is nosurf-CSRF-protected, so each attempt GETs the form (into a fresh
# cookie jar) to read the csrf_token, then POSTs it back with the same cookie.
# The POST creates the admin (password-hashed), writes the instance config and
# marks setup complete. A verifying retry loop absorbs the brief window right
# after boot before the server reliably accepts the POST, and stops as soon as
# GET /setup 302s — which is also the redeploy case (setup already complete),
# making this idempotent. The password is generated once by the prepare hook.
if [ -f /data/.admin_password ]; then
  ADMIN_PW="$(cat /data/.admin_password)"
  CJ="$(mktemp)"
  HTML="$(mktemp)"
  n=0
  while [ "$n" -lt 30 ]; do
    n=$((n + 1))
    code="$(curl -sS -c "$CJ" -o "$HTML" -w '%{http_code}' \
      "http://127.0.0.1:8000/setup" 2>/dev/null || echo 000)"
    if [ "$code" = "302" ]; then
      echo "yarnd: setup already complete."
      break
    fi
    CSRF=""
    if [ "$code" = "200" ]; then
      # yarnd's html/template HTML-escapes the base64 token in the form value
      # (notably '+' -> '&#43;'); unescape it or nosurf rejects the POST (400).
      CSRF="$(grep -o 'name="csrf_token" value="[^"]*"' "$HTML" 2>/dev/null \
        | sed 's/.*value="//; s/"$//' | head -n 1 \
        | sed 's/&#43;/+/g; s/&#47;/\//g; s/&#61;/=/g; s/&amp;/\&/g')"
    fi
    if [ -n "$CSRF" ]; then
      pcode="$(curl -sS -b "$CJ" -o /dev/null -w '%{http_code}' \
        --data-urlencode "csrf_token=${CSRF}" \
        --data-urlencode "baseURL=${BASE_URL}" \
        --data-urlencode "adminUser=${ADMIN_USER:-admin}" \
        --data-urlencode "adminPassword=${ADMIN_PW}" \
        --data-urlencode "adminPasswordConfirm=${ADMIN_PW}" \
        --data-urlencode "podName=${NAME}" \
        --data-urlencode "podDescription=A Yarn.social pod" \
        --data-urlencode "dataPath=/data" \
        --data-urlencode "storePath=bitcask:///data/yarn.db" \
        "http://127.0.0.1:8000/setup" 2>/dev/null || echo 000)"
      if [ "$pcode" = "302" ]; then
        echo "yarnd: admin '${ADMIN_USER:-admin}' seeded via setup wizard."
        break
      fi
      echo "yarnd: setup attempt ${n} -> HTTP ${pcode}, retrying."
    fi
    sleep 2
  done
  rm -f "$CJ" "$HTML"
fi

# 3. yarnd owns the container from here.
wait "$YARN_PID"
