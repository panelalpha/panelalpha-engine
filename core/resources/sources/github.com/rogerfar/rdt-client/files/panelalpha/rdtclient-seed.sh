#!/bin/sh
# Creates rdt-client's login once via POST /Api/Authentication/Create, before
# the proxy publishes anything. An existing user is never touched.
set -eu
: "${RDTCLIENT_ADMIN_USER:?}" "${RDTCLIENT_ADMIN_PASSWORD:?}"
api=http://app:6500/Api/Authentication

# IsLoggedIn: 402 = no user yet, 403 = user exists, 200 = auth set to "None".
code=$(curl -s -o /dev/null -w '%{http_code}' "$api/IsLoggedIn" || true)
case "$code" in
    403) echo "[rdtclient] login user present, nothing to seed"; exit 0 ;;
    200) echo "[rdtclient] authentication is switched off in Settings; left alone"; exit 0 ;;
    402) ;;
    *) echo "[rdtclient] unexpected IsLoggedIn status $code" >&2; exit 1 ;;
esac

# The engine's generated password is [A-Za-z0-9] only, so it needs no JSON escaping.
curl -fsS -o /dev/null -H 'Content-Type: application/json' \
    --data "{\"userName\":\"$RDTCLIENT_ADMIN_USER\",\"password\":\"$RDTCLIENT_ADMIN_PASSWORD\"}" \
    "$api/Create"

code=$(curl -s -o /dev/null -w '%{http_code}' "$api/IsLoggedIn" || true)
[ "$code" = 403 ] || { echo "[rdtclient] login was not created (IsLoggedIn $code)" >&2; exit 1; }
echo "[rdtclient] login user '$RDTCLIENT_ADMIN_USER' created"
