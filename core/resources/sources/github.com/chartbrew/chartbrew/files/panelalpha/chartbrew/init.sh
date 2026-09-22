#!/bin/sh
# One-shot owner bootstrap. Chartbrew has no admin seeding: the FIRST user to
# register via POST /user becomes an active teamOwner with a default project
# (server/controllers/UserController.js createUser -> active:true + teamOwner),
# and with CB_RESTRICT_SIGNUP=1 every later signup is refused once any user
# exists (UserRoute.js: areThereAnyUsers -> 401). So "first visitor wins" unless
# we claim the owner seat ourselves before the site is reachable. This container
# waits for the API, then registers the operator's owner with the generated
# password from ~/.panelalpha/chartbrew/owner.env.
set -e

API="http://chartbrew:4019"

i=0
until curl -fsS "${API}/" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "${i}" -ge 60 ]; then
        echo "[panelalpha/init] API never answered"; exit 1
    fi
    sleep 3
done

# Register the owner. 200/201 = created; 401 = signup already locked (a user
# exists from a previous deploy); 409 = this email already registered. All three
# mean "owner seat is taken", which is the goal, so only a real error fails.
code=$(curl -s -o /tmp/cb_resp -w '%{http_code}' -X POST "${API}/user" \
    -H 'Content-Type: application/json' \
    --data "$(printf '{"name":"%s","email":"%s","password":"%s"}' \
        "${OWNER_NAME}" "${OWNER_EMAIL}" "${OWNER_PASSWORD}")")

case "${code}" in
    200|201) echo "[panelalpha/init] owner ${OWNER_EMAIL} created" ;;
    401|409) echo "[panelalpha/init] owner already present / signup locked (${code})" ;;
    *) echo "[panelalpha/init] unexpected ${code}: $(cat /tmp/cb_resp)"; exit 1 ;;
esac
