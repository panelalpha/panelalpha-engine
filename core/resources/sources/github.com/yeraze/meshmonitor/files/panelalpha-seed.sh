#!/bin/sh
# One-shot, no published port: MeshMonitor creates its first admin with the
# hard-coded password 'changeme'. Start the server privately, replace that
# password through the app's own API, and fail unless 'changeme' is refused.
set -eu
: "${MESHMONITOR_ADMIN_PASSWORD:?missing}"
U="${ADMIN_USERNAME:-admin}"
B=http://127.0.0.1:3001/api
J=/tmp/pa-jar

cd /app
# Read-only look at the admin row: none | default | custom.
admin_state() {
    su-exec node node -e "
const fs=require('fs'),p=process.env.DATABASE_PATH||'/data/meshmonitor.db';
if(!fs.existsSync(p)){console.log('none');process.exit(0)}
const d=new(require('better-sqlite3'))(p,{readonly:true});
let r=[];try{r=d.prepare('SELECT password_hash h FROM users WHERE is_admin=1').all()}catch(e){}
const b=require('bcrypt');
console.log(!r.length?'none':r.some(x=>x.h&&b.compareSync('changeme',x.h))?'default':'custom')" 2>/dev/null || echo none
}

# Redeploy with the password already replaced: nothing to do, and no second
# server on the live database.
if [ "$(admin_state)" = custom ]; then
    echo "meshmonitor-seed: admin password already replaced, default refused"
    exit 0
fi

su-exec node node dist/server/server.js >/tmp/pa-server.log 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true' EXIT
fail() { echo "meshmonitor-seed: $*" >&2; tail -40 /tmp/pa-server.log >&2; exit 1; }

i=0
until curl -fs -o /dev/null "$B/health"; do
    kill -0 "$PID" 2>/dev/null || fail "server exited"
    i=$((i + 1)); [ "$i" -lt 180 ] || fail "server did not answer"
    sleep 1
done

# The admin row is created asynchronously after listen(); wait for it (read-only).
i=0
until [ "$(admin_state)" != none ]; do
    i=$((i + 1)); [ "$i" -lt 60 ] || fail "no admin user was created"
    sleep 1
done

csrf() { curl -fsS -c "$J" -b "$J" "$B/csrf-token" | sed -n 's/.*"csrfToken":"\([0-9a-f]*\)".*/\1/p'; }
login() {
    rm -f "$J"
    curl -sS -o /dev/null -w '%{http_code}' -c "$J" -b "$J" -H "X-CSRF-Token: $(csrf)" \
        -H 'Content-Type: application/json' -d "{\"username\":\"$U\",\"password\":\"$1\"}" "$B/auth/login"
}

if [ "$(login changeme)" = 200 ]; then
    code=$(curl -sS -o /tmp/pa-change.json -w '%{http_code}' -c "$J" -b "$J" -H "X-CSRF-Token: $(csrf)" \
        -H 'Content-Type: application/json' \
        -d "{\"currentPassword\":\"changeme\",\"newPassword\":\"$MESHMONITOR_ADMIN_PASSWORD\"}" "$B/auth/change-password")
    [ "$code" = 200 ] || fail "change-password returned $code: $(cat /tmp/pa-change.json)"
    [ "$(login "$MESHMONITOR_ADMIN_PASSWORD")" = 200 ] || fail "generated password does not log in"
    echo "meshmonitor-seed: admin '$U' password set from ~/.panelalpha/meshmonitor/admin.env"
fi

[ "$(login changeme)" = 401 ] || fail "default password is still accepted"
echo "meshmonitor-seed: default password refused"
