#!/bin/sh
# Creates Prowlarr's Forms-login user once, via PUT /api/v1/config/host.
# Env: PROWLARR_ADMIN_USER, PROWLARR_ADMIN_PASSWORD (~/.panelalpha/prowlarr/admin.env).
set -eu
API=http://app:9696/api/v1

# Prowlarr generates its API key into config.xml on first boot.
key=""
for _ in $(seq 1 30); do
    key=$(xmlstarlet sel -t -v /Config/ApiKey /config/config.xml 2>/dev/null || true)
    [ -n "$key" ] && break
    sleep 2
done
[ -n "$key" ] || { echo "prowlarr: no ApiKey in /config/config.xml" >&2; exit 1; }

host=$(curl -fsS -H "X-Api-Key: $key" "$API/config/host")
if [ -n "$(printf '%s' "$host" | jq -r '.username // ""')" ]; then
    echo "prowlarr: login user present, nothing to seed"
    exit 0
fi

# The endpoint saves the whole resource, so send back what it returned.
printf '%s' "$host" | jq --arg u "$PROWLARR_ADMIN_USER" --arg p "$PROWLARR_ADMIN_PASSWORD" \
    '.authenticationMethod="forms" | .authenticationRequired="enabled"
     | .username=$u | .password=$p | .passwordConfirmation=$p' \
  | curl -fsS -o /dev/null -X PUT -H "X-Api-Key: $key" -H 'Content-Type: application/json' \
      --data-binary @- "$API/config/host/1"

user=$(curl -fsS -H "X-Api-Key: $key" "$API/config/host" | jq -r '.username // ""')
[ "$user" = "$PROWLARR_ADMIN_USER" ] || { echo "prowlarr: login user was not created" >&2; exit 1; }
echo "prowlarr: login user '$user' created"
