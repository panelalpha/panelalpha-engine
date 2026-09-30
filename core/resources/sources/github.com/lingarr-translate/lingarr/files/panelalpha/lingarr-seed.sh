#!/bin/sh
# Creates the first admin and completes onboarding through Lingarr's own API,
# before the proxy publishes anything. Idempotent: never touches existing users.
set -eu
: "${LINGARR_ADMIN_USER:?}" "${LINGARR_ADMIN_PASSWORD:?}"
api=http://app:9876/api/auth

i=0
until any=$(wget -qO- "$api/users/any" 2>/dev/null); do
    i=$((i + 1))
    [ "$i" -lt 120 ] || { echo "[lingarr] app never answered" >&2; exit 1; }
    sleep 2
done

post() {
    wget -qO- --header 'Content-Type: application/json' --post-data "$2" "$api/$1" >/dev/null
}

if [ "$any" = "false" ]; then
    # The engine's generated password is [A-Za-z0-9] only, so it needs no JSON escaping.
    post signup "{\"username\":\"$LINGARR_ADMIN_USER\",\"password\":\"$LINGARR_ADMIN_PASSWORD\"}"
    echo "[lingarr] admin '$LINGARR_ADMIN_USER' created"
else
    echo "[lingarr] users exist; left alone"
fi

# 200 with requiresOnboarding only while onboarding is incomplete; 401 after.
state=$(wget -qO- "$api/authenticated" 2>/dev/null || true)
case "$state" in
    *'"requiresOnboarding":true'*)
        post onboarding '{"enableUserAuth":"true"}'
        echo "[lingarr] onboarding completed with authentication on"
        ;;
esac
