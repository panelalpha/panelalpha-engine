#!/bin/sh
# Two things Rocket.Chat cannot be told any other way, done once the server
# answers.
#
# 1. Registration. Accounts_RegistrationForm defaults to 'Public'
#    (apps/meteor/server/settings/accounts.ts), so a fresh instance on a public
#    HTTPS name accepts a signup from anyone — creating the admin from the
#    environment closes the setup wizard but not the front door. This could
#    have been OVERWRITE_SETTING_Accounts_RegistrationForm=Disabled in the
#    compose file, and deliberately is not: a value supplied through the
#    environment is locked, and an operator inviting a team should be able to
#    re-open registration from the admin area. Setting it through the API
#    leaves the field editable.
#
# 2. Site_Url. Rocket.Chat builds permalinks, invitations, password resets and
#    OAuth redirects from it. Its default is the ROOT_URL the compose file
#    carries, which the engine has already rewritten to the account's address —
#    but a default is only consulted for a setting that has never been stored,
#    and on the second deploy of an account whose domain changed the stored
#    value is what the server serves. So it is re-asserted here.
#
# Signing in is also the proof the recipe wants: that the generated password
# actually works against the account the server created from the environment,
# rather than that a container started.
#
# Best effort, and always exit 0. The `ready` gate depends on this completing
# successfully, so a non-zero exit would fail `docker compose up -d` and take a
# working deploy down with it.

BASE="${RC_SETUP_URL:-http://rocketchat:3000}"
USERNAME="${RC_ADMIN_USERNAME}"
PASSWORD="${RC_ADMIN_PASSWORD}"
SITE_URL="${PA_PUBLIC_URL}"

say() { echo "[panelalpha-setup] $*"; }
finish() { exit 0; }

if [ -z "${USERNAME}" ] || [ -z "${PASSWORD}" ]; then
    say "no admin credentials in the environment; nothing to do"
    finish
fi

# Rocket.Chat is healthy before this service starts, but /livez answers earlier
# than /api/info does — the REST layer is registered after the server begins
# serving — so this waits for the API rather than assuming it.
i=0
while [ "${i}" -lt 60 ]; do
    if curl -fsS -m 5 "${BASE}/api/info" >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done

# The admin was created by insertAdminUserFromEnv() during boot, from the same
# ADMIN_* values; this is the first time anything checks that it can be used.
code=$(curl -s -m 30 -o /tmp/pa-login.json -w '%{http_code}' \
    -X POST "${BASE}/api/v1/login" \
    -H 'Content-Type: application/json' \
    -d "{\"user\":\"${USERNAME}\",\"password\":\"${PASSWORD}\"}")
if [ "${code}" != "200" ]; then
    say "sign-in as ${USERNAME} returned ${code}: $(cat /tmp/pa-login.json 2>/dev/null)"
    finish
fi

# {"status":"success","data":{"authToken":"…","userId":"…"}} — no jq in this
# image, and the two fields are adjacent and unambiguous.
TOKEN=$(sed -n 's/.*"authToken" *: *"\([^"]*\)".*/\1/p' /tmp/pa-login.json)
USER_ID=$(sed -n 's/.*"userId" *: *"\([^"]*\)".*/\1/p' /tmp/pa-login.json)
if [ -z "${TOKEN}" ] || [ -z "${USER_ID}" ]; then
    say "sign-in succeeded but returned no token; leaving settings alone"
    finish
fi
say "signed in as ${USERNAME}"

set_setting() {
    _id="$1"
    _body="$2"
    _code=$(curl -s -m 30 -o /tmp/pa-set.json -w '%{http_code}' \
        -X POST "${BASE}/api/v1/settings/${_id}" \
        -H "X-Auth-Token: ${TOKEN}" \
        -H "X-User-Id: ${USER_ID}" \
        -H 'Content-Type: application/json' \
        -d "${_body}")
    if [ "${_code}" = "200" ]; then
        say "${_id} set"
    else
        say "POST /api/v1/settings/${_id} returned ${_code}: $(cat /tmp/pa-set.json 2>/dev/null)"
    fi
}

set_setting Accounts_RegistrationForm '{"value":"Disabled"}'

if [ -z "${SITE_URL}" ] || [ "${SITE_URL}" = "http://localhost" ]; then
    say "no public URL was substituted into PA_PUBLIC_URL; leaving Site_Url alone"
    finish
fi
set_setting Site_Url "{\"value\":\"${SITE_URL}\"}"

finish
