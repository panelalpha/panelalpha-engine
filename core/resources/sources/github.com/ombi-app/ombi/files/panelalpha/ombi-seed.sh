#!/bin/sh
# Runs Ombi privately on 127.0.0.1 inside this one-shot container, completes the
# first-run wizard with the generated admin, then stops it. The public app
# service starts only after this exits 0, so the wizard is never reachable.
# Env: OMBI_ADMIN_USER, OMBI_ADMIN_PASSWORD (~/.panelalpha/ombi/admin.env), PA_PUBLIC_URL.
set -eu
API=http://127.0.0.1:3579/api
LOG=/run/ombi-temp/seed.log

mkdir -p /run/ombi-temp
cd /app/ombi
/app/ombi/Ombi --storage /config --host http://127.0.0.1:3579 >"$LOG" 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; chown -R 1000:1000 /config' EXIT

up=""
for _ in $(seq 1 180); do
    if curl -fs -o /dev/null "$API/v1/Status"; then up=1; break; fi
    kill -0 "$pid" 2>/dev/null || break
    sleep 1
done
[ -n "$up" ] || { tail -n 50 "$LOG" >&2; echo "ombi: did not start" >&2; exit 1; }

wizard() { curl -fsS "$API/v1/Status/Wizard" | jq -r '.result // .Result'; }
if [ "$(wizard)" = "true" ]; then
    echo "ombi: wizard already completed, nothing to seed"
    exit 0
fi

# Application URL first: the wizard's config endpoint refuses once it is done.
jq -n --arg u "${PA_PUBLIC_URL:-}" '{applicationName: "Ombi", applicationUrl: $u}' \
  | curl -fsS -o /dev/null -X POST -H 'Content-Type: application/json' --data-binary @- "$API/v2/Wizard/config"

res=$(jq -n --arg u "$OMBI_ADMIN_USER" --arg p "$OMBI_ADMIN_PASSWORD" \
        '{username: $u, password: $p, usePlexAdminAccount: false}' \
  | curl -fsS -X POST -H 'Content-Type: application/json' --data-binary @- "$API/v1/Identity/Wizard")
[ "$(printf '%s' "$res" | jq -r '.result // .Result')" = "true" ] || { echo "ombi: wizard user not created: $res" >&2; exit 1; }
[ "$(wizard)" = "true" ] || { echo "ombi: wizard flag not set" >&2; exit 1; }
echo "ombi: admin '$OMBI_ADMIN_USER' created, wizard completed"

# POST /api/v1/Jellyfin and /api/v1/Emby stay anonymous while no server is
# saved; a disabled placeholder server closes them until the admin sets one.
tok=$(jq -n --arg u "$OMBI_ADMIN_USER" --arg p "$OMBI_ADMIN_PASSWORD" '{username: $u, password: $p}' \
  | curl -fsS -X POST -H 'Content-Type: application/json' --data-binary @- "$API/v1/Token" | jq -r .access_token)
for s in jellyfin emby; do
    printf '%s' '{"enable":false,"servers":[{"name":"Not configured","ip":"","port":8096,"ssl":false,"apiKey":""}]}' \
      | curl -fsS -o /dev/null -X POST -H "Authorization: Bearer $tok" -H 'Content-Type: application/json' \
          --data-binary @- "$API/v1/Settings/$s"
    n=$(curl -fsS -H "Authorization: Bearer $tok" "$API/v1/Settings/$s" | jq '.servers | length')
    [ "$n" -ge 1 ] || { echo "ombi: $s placeholder not saved" >&2; exit 1; }
done
echo "ombi: disabled Jellyfin/Emby placeholders saved"
