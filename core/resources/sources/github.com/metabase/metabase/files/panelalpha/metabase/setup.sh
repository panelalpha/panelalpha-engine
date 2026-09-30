#!/bin/bash
# One-shot before the public app: boot Metabase on loopback only, create the
# admin through /api/setup with its setup token, stop. Skipped once done.
set -eu

say() { echo "[panelalpha/setup] $*"; }
MARK=/state/setup-done

if [ -f "${MARK}" ]; then
    say "setup already completed"
    exit 0
fi

: "${MB_ADMIN_PASSWORD:?MB_ADMIN_PASSWORD is not set}"
EMAIL="${MB_ADMIN_EMAIL:?MB_ADMIN_EMAIL is not set}"
BASE=http://127.0.0.1:3000

# Nothing outside this container can reach it while the wizard is open.
export MB_JETTY_HOST=127.0.0.1
/app/run_metabase.sh &
PID=$!

stop() {
    kill "${PID}" 2>/dev/null || true
    wait "${PID}" 2>/dev/null || true
}

healthy=""
for _ in $(seq 1 300); do
    if curl -fsS "${BASE}/api/health" 2>/dev/null | grep -q '"ok"'; then
        healthy=1
        break
    fi
    kill -0 "${PID}" 2>/dev/null || { say "Metabase exited during boot"; exit 1; }
    sleep 2
done
[ -n "${healthy}" ] || { say "Metabase did not become healthy"; stop; exit 1; }

props="$(curl -fsS "${BASE}/api/session/properties")"
if printf '%s' "${props}" | grep -q '"has-user-setup":true'; then
    say "an admin already exists; nothing to do"
else
    token="$(printf '%s' "${props}" | grep -o '"setup-token":"[^"]*"' | cut -d'"' -f4)"
    [ -n "${token}" ] || { say "no setup token in /api/session/properties"; stop; exit 1; }
    body=$(printf '{"token":"%s","user":{"first_name":"Admin","last_name":"PanelAlpha","email":"%s","password":"%s"},"prefs":{"site_name":"Metabase","site_locale":"en"}}' \
        "${token}" "${EMAIL}" "${MB_ADMIN_PASSWORD}")
    code="$(curl -sS -o /tmp/setup.json -w '%{http_code}' -H 'Content-Type: application/json' \
        -d "${body}" "${BASE}/api/setup")"
    if [ "${code}" != "200" ]; then
        say "POST /api/setup answered ${code}: $(head -c 500 /tmp/setup.json)"
        stop
        exit 1
    fi
    say "admin ${EMAIL} created"
fi

touch "${MARK}"
stop
say "done"
