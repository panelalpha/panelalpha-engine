#!/bin/sh
# One-shot after Drop answers: creates the first admin with the engine's login
# through Drop's own setup API, the steps its /setup wizard takes:
#   1. read the setup token Drop makes on a start with no user (it only logs it)
#   2. POST /api/v1/admin/auth/invitation {isAdmin: true} with that token
#   3. POST /api/v1/auth/signup/simple with the invitation and the login
#   4. POST /api/v1/setup, which deletes the token (the wizard's "finish")
# Nothing happens once any user exists, so a password changed in Drop stays.
# It never fails the deploy: on an error it says so and Drop keeps its wizard.
DROP=http://drop:3000
# db.env (the database's own env file) names it POSTGRES_PASSWORD; psql reads PGPASSWORD.
export PGPASSWORD="${PGPASSWORD:-${POSTGRES_PASSWORD:-}}"
log() { echo "[panelalpha] drop: $*"; }
sql() { psql -qtAX -c "$1" 2>/dev/null; }
json() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

if [ -z "${DROP_ADMIN_PASSWORD:-}" ]; then
    log "no DROP_ADMIN_PASSWORD in app-credentials.env; leaving the setup wizard"
    exit 0
fi

# Drop makes the token in a startup plugin; give it a moment after the first answer.
i=0
while :; do
    users=$(sql "SELECT count(*) FROM \"User\" WHERE id <> 'system'")
    if [ -n "$users" ] && [ "$users" != 0 ]; then
        log "Drop already has a user; its login is left alone"
        exit 0
    fi
    token=$(sql "SELECT token FROM \"APIToken\" WHERE mode = 'System' AND 'setup' = ANY(acls) LIMIT 1")
    [ -n "$token" ] && break
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        log "found no setup token; leaving the setup wizard"
        exit 0
    fi
    sleep 2
done

user=$(json "${DROP_ADMIN_USERNAME:-admin}")
email=$(json "${DROP_ADMIN_EMAIL:-admin@example.com}")
pass=$(json "$DROP_ADMIN_PASSWORD")
expires=$(date -u -d "@$(( $(date +%s) + 3600 ))" +%Y-%m-%dT%H:%M:%SZ)

post() { # path, body, [bearer]
    wget -q -O - --header 'Content-Type: application/json' \
        ${3:+--header "Authorization: Bearer $3"} --post-data "$2" "$DROP$1"
}

invite=$(post /api/v1/admin/auth/invitation \
    "{\"username\":\"$user\",\"email\":\"$email\",\"isAdmin\":true,\"expires\":\"$expires\"}" "$token") \
    || { log "could not create the admin invitation; leaving the setup wizard"; exit 0; }
id=$(printf '%s' "$invite" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
[ -n "$id" ] || { log "the invitation had no id; leaving the setup wizard"; exit 0; }

post /api/v1/auth/signup/simple \
    "{\"invitation\":\"$id\",\"username\":\"$user\",\"email\":\"$email\",\"password\":\"$pass\"}" >/dev/null \
    || { log "could not create the admin account; leaving the setup wizard"; exit 0; }

post /api/v1/setup "{}" "$token" >/dev/null || log "the admin exists, but the setup token was not closed"
log "the first admin's login is the one the engine generated"
