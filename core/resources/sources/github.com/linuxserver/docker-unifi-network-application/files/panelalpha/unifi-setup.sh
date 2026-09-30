#!/bin/bash
# Closes the first-run wizard with the engine's admin login, using the calls the
# wizard itself makes (app-unifi/setup, configureController). Until
# `set-installed` the controller serves every API call anonymously as a super
# admin ("factory_default"), so the proxy waits for this to succeed.
set -u
: "${UNIFI_ADMIN_USER:?}" "${UNIFI_ADMIN_EMAIL:?}" "${UNIFI_ADMIN_PASSWORD:?}"
BASE="https://unifi:8443"
JAR=$(mktemp)
OUT=$(mktemp)

for _ in $(seq 1 120); do
    curl -ksf --max-time 5 "${BASE}/status" | grep -q '"up":true' && break
    sleep 5
done

# Anonymous /api/self: 200 while the wizard is open, 401 once it is closed.
code=$(curl -ks --max-time 30 -o /dev/null -w '%{http_code}' "${BASE}/api/self")
case "${code}" in
    401) echo "[unifi-setup] already set up"; exit 0 ;;
    200) ;;
    *) echo "[unifi-setup] unexpected /api/self status ${code}"; exit 1 ;;
esac

post() {
    local csrf rc
    csrf=$(awk '$6 == "csrf_token" { print $7 }' "${JAR}" | tail -1)
    rc=$(curl -ksS --max-time 60 -b "${JAR}" -c "${JAR}" -H 'Content-Type: application/json' \
        ${csrf:+-H "X-Csrf-Token: ${csrf}"} -o "${OUT}" -w '%{http_code}' -X POST -d "$2" "${BASE}$1")
    if [ "${rc}" != 200 ] || ! grep -q '"rc":"ok"' "${OUT}"; then
        echo "[unifi-setup] $1 failed: HTTP ${rc} $(head -c 300 "${OUT}")"
        exit 1
    fi
}

post /api/cmd/sitemgr "$(jq -nc --arg n "${UNIFI_ADMIN_USER}" --arg e "${UNIFI_ADMIN_EMAIL}" --arg p "${UNIFI_ADMIN_PASSWORD}" \
    '{cmd:"add-default-admin", name:$n, email:$e, x_password:$p}')"
post /api/set/setting/super_identity '{"name":"UniFi Network"}'
# Device SSH credentials, random as the wizard sets them.
post /api/set/setting/mgmt "$(jq -nc --arg u "${UNIFI_ADMIN_USER}" --arg p "$(openssl rand -hex 16)" \
    '{x_ssh_username:$u, x_ssh_password:$p}')"
post /api/cmd/system '{"cmd":"set-installed"}'

code=$(curl -ks --max-time 30 -o /dev/null -w '%{http_code}' "${BASE}/api/self")
if [ "${code}" != 401 ]; then
    echo "[unifi-setup] wizard still open after set-installed (/api/self ${code})"
    exit 1
fi
echo "[unifi-setup] admin ${UNIFI_ADMIN_USER} created, wizard closed"
