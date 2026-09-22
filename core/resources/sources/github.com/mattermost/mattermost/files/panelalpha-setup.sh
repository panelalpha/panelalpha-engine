#!/bin/sh
# Two things Mattermost cannot be told any other way, done once the server
# answers.
#
# 1. The system admin. Mattermost has no installer and no admin environment
#    variables: with zero rows in Users, POST /api/v4/users needs no token
#    (api4/user.go permits it when c.App.IsFirstUserAccount()) and app/user.go
#    gives that first account system_admin + system_user. On a public HTTPS name
#    that means the instance belongs to the first stranger who loads it. Making
#    that request here is what closes the door: from the second account onwards
#    the same endpoint is refused, because TeamSettings.EnableOpenServer is
#    false by default.
#
# 2. SiteURL. Mattermost builds permalinks, invitations, password resets,
#    OAuth redirects and every notification link from it, and the System Console
#    reports an unset one as an error. It is not known until the account's
#    domain exists, so it cannot be baked in by the prepare hook — and the
#    environment variable that would carry it, MM_SERVICESETTINGS_SITEURL, is
#    not a name the engine's placeholder pass recognises (it ends in SITEURL,
#    not _URL). PA_PUBLIC_URL below is a name it does recognise, so the account's
#    address arrives here and goes in through PUT /api/v4/config/patch. Doing it
#    through the API rather than the environment also leaves the field editable
#    in the System Console: a setting supplied by an env var is locked there.
#
# Best effort, and always exit 0. The `ready` gate depends on this completing
# successfully, so a non-zero exit would fail `docker compose up -d` and take a
# working deploy down with it.

BASE="${MM_SETUP_URL:-http://mattermost:8065}"
EMAIL="${MM_ADMIN_EMAIL}"
USERNAME="${MM_ADMIN_USERNAME}"
PASSWORD="${MM_ADMIN_PASSWORD}"
SITE_URL="${PA_PUBLIC_URL}"

say() { echo "[panelalpha-setup] $*"; }
finish() { exit 0; }

if [ -z "${EMAIL}" ] || [ -z "${USERNAME}" ] || [ -z "${PASSWORD}" ]; then
    say "no admin credentials in the environment; nothing to do"
    finish
fi

# Mattermost is healthy before this service starts, but its healthcheck is
# mmctl on the local socket rather than an HTTP request, so this is the first
# thing that actually asks the web server for anything.
i=0
while [ "${i}" -lt 60 ]; do
    if curl -fsS -m 5 "${BASE}/api/v4/system/ping" >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done

code=$(curl -s -m 30 -o /tmp/pa-user.json -w '%{http_code}' \
    -X POST "${BASE}/api/v4/users" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"${EMAIL}\",\"username\":\"${USERNAME}\",\"password\":\"${PASSWORD}\"}")
case "${code}" in
    200 | 201)
        say "created ${USERNAME} <${EMAIL}>; it is the system admin, and signup is now closed"
        ;;
    *)
        # 403 with an existing admin is the normal redeploy case: the endpoint
        # stops being open the moment there is a first account.
        say "POST /api/v4/users returned ${code}: $(cat /tmp/pa-user.json 2>/dev/null)"
        ;;
esac

if [ -z "${SITE_URL}" ] || [ "${SITE_URL}" = "http://localhost" ]; then
    say "no public URL was substituted into PA_PUBLIC_URL; leaving SiteURL alone"
    finish
fi

# Log in as the admin — which is also the proof that the account works, rather
# than that a 201 came back.
code=$(curl -s -m 30 -D /tmp/pa-login.h -o /tmp/pa-login.json -w '%{http_code}' \
    -X POST "${BASE}/api/v4/users/login" \
    -H 'Content-Type: application/json' \
    -d "{\"login_id\":\"${EMAIL}\",\"password\":\"${PASSWORD}\"}")
if [ "${code}" != "200" ]; then
    say "sign-in as ${EMAIL} returned ${code}; leaving SiteURL unset"
    finish
fi
TOKEN=$(sed -n 's/^[Tt]oken:[[:space:]]*//p' /tmp/pa-login.h | tr -d '\r')
if [ -z "${TOKEN}" ]; then
    say "sign-in returned no Token header; leaving SiteURL unset"
    finish
fi
say "signed in as ${USERNAME}"

# patchConfig merges into what is stored, so these keys are enough. SiteURL is
# applied live — the server does not need a restart.
code=$(curl -s -m 30 -o /tmp/pa-config.json -w '%{http_code}' \
    -X PUT "${BASE}/api/v4/config/patch" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"ServiceSettings\":{\"SiteURL\":\"${SITE_URL}\"},\"TeamSettings\":{\"EnableOpenServer\":false}}")
if [ "${code}" = "200" ]; then
    say "SiteURL is ${SITE_URL}; open registration is off"
else
    say "PUT /api/v4/config/patch returned ${code}: $(cat /tmp/pa-config.json 2>/dev/null)"
fi

# A team, so the admin's first sign-in lands in a channel instead of on the
# "create a team" screen. Creating one makes the creator its admin. Entirely
# cosmetic: a failure here is not worth a word of alarm.
code=$(curl -s -m 30 -o /tmp/pa-team.json -w '%{http_code}' \
    -X POST "${BASE}/api/v4/teams" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H 'Content-Type: application/json' \
    -d '{"name":"main","display_name":"Main","type":"O"}')
case "${code}" in
    200 | 201) say "created the Main team" ;;
    *) say "no team created (POST /api/v4/teams returned ${code})" ;;
esac

finish
