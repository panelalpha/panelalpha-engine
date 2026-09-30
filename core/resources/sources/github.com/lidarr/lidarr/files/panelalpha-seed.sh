#!/bin/sh
# Creates Lidarr's Forms-login user once, via PUT /api/v1/config/host.
# Env: LIDARR_ADMIN_USER, LIDARR_ADMIN_PASSWORD (~/.panelalpha/lidarr/admin.env).
set -eu
API=http://app:8686/api/v1

# The library volume is created root-owned; the app runs as PUID 1000.
chown 1000:1000 /music

# Lidarr generates its API key into config.xml on first boot.
key=""
for _ in $(seq 1 30); do
    key=$(xmlstarlet sel -t -v /Config/ApiKey /config/config.xml 2>/dev/null || true)
    [ -n "$key" ] && break
    sleep 2
done
[ -n "$key" ] || { echo "lidarr: no ApiKey in /config/config.xml" >&2; exit 1; }

host=$(curl -fsS -H "X-Api-Key: $key" "$API/config/host")
if [ -n "$(printf '%s' "$host" | jq -r '.username // ""')" ]; then
    echo "lidarr: login user present, nothing to seed"
    exit 0
fi

# The endpoint saves the whole resource, so send back what it returned.
printf '%s' "$host" | jq --arg u "$LIDARR_ADMIN_USER" --arg p "$LIDARR_ADMIN_PASSWORD" \
    '.authenticationMethod="forms" | .authenticationRequired="enabled"
     | .username=$u | .password=$p | .passwordConfirmation=$p' \
  | curl -fsS -o /dev/null -X PUT -H "X-Api-Key: $key" -H 'Content-Type: application/json' \
      --data-binary @- "$API/config/host/1"

user=$(curl -fsS -H "X-Api-Key: $key" "$API/config/host" | jq -r '.username // ""')
[ "$user" = "$LIDARR_ADMIN_USER" ] || { echo "lidarr: login user was not created" >&2; exit 1; }
echo "lidarr: login user '$user' created"
