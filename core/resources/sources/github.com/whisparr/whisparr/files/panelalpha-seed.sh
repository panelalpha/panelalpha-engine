#!/bin/sh
# Creates Whisparr's Forms-login user once, via PUT /api/v3/config/host.
# Env: WHISPARR_ADMIN_USER, WHISPARR_ADMIN_PASSWORD (~/.panelalpha/whisparr/admin.env).
set -eu
API=http://app:6969/api/v3

# Whisparr generates its API key into config.xml on first boot.
key=""
for _ in $(seq 1 30); do
    key=$(sed -n 's#.*<ApiKey>\([^<]*\)</ApiKey>.*#\1#p' /config/config.xml 2>/dev/null || true)
    [ -n "$key" ] && break
    sleep 2
done
[ -n "$key" ] || { echo "whisparr: no ApiKey in /config/config.xml" >&2; exit 1; }

host=$(curl -fsS -H "X-Api-Key: $key" "$API/config/host")
if [ -n "$(printf '%s' "$host" | jq -r '.username // ""')" ]; then
    echo "whisparr: login user present, nothing to seed"
    exit 0
fi

# The endpoint saves the whole resource, so send back what it returned.
printf '%s' "$host" | jq --arg u "$WHISPARR_ADMIN_USER" --arg p "$WHISPARR_ADMIN_PASSWORD" \
    '.authenticationMethod="forms" | .authenticationRequired="enabled"
     | .username=$u | .password=$p | .passwordConfirmation=$p' \
  | curl -fsS -o /dev/null -X PUT -H "X-Api-Key: $key" -H 'Content-Type: application/json' \
      --data-binary @- "$API/config/host/1"

user=$(curl -fsS -H "X-Api-Key: $key" "$API/config/host" | jq -r '.username // ""')
[ "$user" = "$WHISPARR_ADMIN_USER" ] || { echo "whisparr: login user was not created" >&2; exit 1; }
echo "whisparr: login user '$user' created"
