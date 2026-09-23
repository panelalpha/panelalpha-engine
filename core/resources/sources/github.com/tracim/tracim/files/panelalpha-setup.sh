#!/bin/sh
# Take Tracim's seeded administrator off its published password.
#
# tracim_backend/command/database.py, InitializeDBCommand._populate_database():
#
#     user_api.create_user(
#         name="Global manager", username="TheAdmin",
#         email="admin@admin.admin", password="admin@admin.admin",
#         profile=Profile.ADMIN, ...)
#
# Not a placeholder and not printed anywhere for the operator to change — it is
# what every Tracim gets on its first `tracimcli db init`, and this one is
# reachable on a public HTTPS name. So the recipe changes it, over Tracim's own
# REST API, from a container that runs once and exits.
#
# Over the API rather than `tracimcli user update` because tracimcli lives
# inside the Tracim container, whose entrypoint ends in `tail -f` and cannot be
# extended without wrapping its process tree. The API needs no such surgery:
# HTTP Basic is one of the authentication policies the backend installs
# (tracim_backend/__init__.py: TracimBasicAuthAuthenticationPolicy), and
# PUT /api/users/{id}/password takes the current password in the body.
#
# Runs after the tracim service is healthy, so the API is already answering.
set -u

API="http://tracim:80/api"
LOGIN="${TRACIM_ADMIN_LOGIN:-admin@admin.admin}"
SHIPPED="${TRACIM_SHIPPED_PASSWORD:-admin@admin.admin}"
WANTED="${TRACIM_ADMIN_PASSWORD:-}"

say() { echo "[panelalpha] $*" >&2; }

if [ -z "${WANTED}" ]; then
    say "TRACIM_ADMIN_PASSWORD is empty; the admin would keep the password Tracim ships"
    exit 1
fi

# JSON string escaping for the two values that reach a request body. The
# generated password is alphanumeric by construction and the login is an email
# address, so this only has to survive what a hand-edited .env could put there.
json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

# POST /api/auth/login, printing only the status code. 200 means the
# credentials are real: this is the "verify a real login" step, not a probe.
login_status() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
        -X POST "${API}/auth/login" \
        -H 'Content-Type: application/json' \
        -d "{\"email\":\"$(json_escape "${LOGIN}")\",\"password\":\"$(json_escape "$1")\"}"
}

# Already done — the usual case on a redeploy, when the SQLite volume survived
# and .env carries the password that was set against it the first time.
if [ "$(login_status "${WANTED}")" = "200" ]; then
    say "the admin password is already the recorded one; nothing to do"
    exit 0
fi

# Neither the recorded password nor the shipped one works, which means somebody
# changed it in Tracim's own UI after the first deploy. That is theirs to keep;
# overwriting it would be the recipe taking an account away from its owner.
if [ "$(login_status "${SHIPPED}")" != "200" ]; then
    say "the admin password is neither the recorded one nor the one Tracim ships;"
    say "somebody has changed it from inside Tracim. Leaving it alone."
    say "~/project/.panelalpha-admin-password is therefore out of date."
    exit 0
fi

# user_id for the PUT path. Fixed at 1 on a fresh database, asked for anyway:
# whoami is the endpoint that both proves Basic auth reaches the backend and
# says which user it reached.
USER_ID=$(curl -s --max-time 20 -u "${LOGIN}:${SHIPPED}" "${API}/auth/whoami" \
    | sed -n 's/.*"user_id"[[:space:]]*:[[:space:]]*\([0-9]\{1,\}\).*/\1/p' | head -1)
case "${USER_ID}" in
    ''|*[!0-9]*)
        say "could not read the admin's user_id from /api/auth/whoami"
        exit 1
        ;;
esac

# 204 on success. 403 is WrongUserPassword, 400 is PasswordDoNotMatch.
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
    -X PUT "${API}/users/${USER_ID}/password" \
    -u "${LOGIN}:${SHIPPED}" \
    -H 'Content-Type: application/json' \
    -d "{\"loggedin_user_password\":\"$(json_escape "${SHIPPED}")\",\"new_password\":\"$(json_escape "${WANTED}")\",\"new_password2\":\"$(json_escape "${WANTED}")\"}")
if [ "${CODE}" != "204" ]; then
    say "PUT /api/users/${USER_ID}/password answered ${CODE}, not 204"
    say "the administrator still has the password Tracim ships; refusing to call this deploy done"
    exit 1
fi

# The password was accepted; this is the check that it is also the one that now
# authenticates. A 204 from the setter and a 401 from the login would mean the
# account is locked out rather than secured.
if [ "$(login_status "${WANTED}")" != "200" ]; then
    say "the password was changed but a login with it failed; the admin account is now unreachable"
    exit 1
fi

say "admin ${LOGIN} now uses the password in ~/project/.panelalpha-admin-password"
exit 0
