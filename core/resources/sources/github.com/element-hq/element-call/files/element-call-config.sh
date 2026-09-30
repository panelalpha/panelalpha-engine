#!/bin/sh
# Writes Element Call's config.json from the project's env vars; fails without a homeserver.
set -eu
url="${ELEMENT_CALL_HOMESERVER_URL:-}"
name="${ELEMENT_CALL_SERVER_NAME:-}"
livekit="${ELEMENT_CALL_LIVEKIT_SERVICE_URL:-}"
re='^https://[A-Za-z0-9.:/_-]+$'

if [ -z "$url" ]; then
    echo "element-call: ELEMENT_CALL_HOMESERVER_URL is not set. Set it to your Matrix homeserver's client URL (e.g. https://matrix.example.com), optionally ELEMENT_CALL_SERVER_NAME (e.g. example.com) and ELEMENT_CALL_LIVEKIT_SERVICE_URL, then redeploy." >&2
    exit 1
fi
echo "$url" | grep -Eq "$re" || { echo "element-call: ELEMENT_CALL_HOMESERVER_URL must be an https:// URL, got '$url'." >&2; exit 1; }
[ -z "$livekit" ] || echo "$livekit" | grep -Eq "$re" || { echo "element-call: ELEMENT_CALL_LIVEKIT_SERVICE_URL must be an https:// URL, got '$livekit'." >&2; exit 1; }
# Server name defaults to the homeserver URL's host.
[ -n "$name" ] || name=$(echo "$url" | sed -E 's#^https://([^/:]+).*#\1#')
echo "$name" | grep -Eq '^[A-Za-z0-9.:-]+$' || { echo "element-call: ELEMENT_CALL_SERVER_NAME '$name' is not a server name." >&2; exit 1; }

lk=""
[ -z "$livekit" ] || lk=", \"livekit\": {\"livekit_service_url\": \"$livekit\"}"
printf '{"default_server_config": {"m.homeserver": {"base_url": "%s", "server_name": "%s"}}%s}\n' \
    "${url%/}" "$name" "$lk" > /etc/element-call/config.json.tmp
chmod 0644 /etc/element-call/config.json.tmp
mv /etc/element-call/config.json.tmp /etc/element-call/config.json
echo "element-call: config.json written for homeserver ${url%/} ($name)"
