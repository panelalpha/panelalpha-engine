#!/bin/sh
# Walks Prismarr's own setup wizard (admin account, then "finish") from
# ~/.panelalpha/app-credentials.env. Runs as the CMD under the image's s6 /init
# in a container with no published port, so nobody else can reach /setup.
set -eu
: "${PRISMARR_ADMIN_EMAIL:?missing}" "${PRISMARR_ADMIN_PASSWORD:?missing}"
URL=http://127.0.0.1:7070
JAR=$(mktemp)
trap 'rm -f "$JAR" /tmp/seed.html' EXIT

code() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
token() {
    curl -fsS -b "$JAR" -c "$JAR" -o /tmp/seed.html "$URL$1"
    sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' /tmp/seed.html | head -n 1
}

for _ in $(seq 1 120); do
    curl -fs -o /dev/null "$URL/api/health" && break
    sleep 1
done
curl -fsS -o /dev/null "$URL/api/health"

# /setup/admin renders the form (200) only while no user exists.
if [ "$(code "$URL/setup/admin")" = 200 ]; then
    t=$(token /setup/admin)
    c=$(code --data-urlencode "_csrf_token=$t" --data-urlencode "email=$PRISMARR_ADMIN_EMAIL" \
        --data-urlencode "display_name=Admin" --data-urlencode "password=$PRISMARR_ADMIN_PASSWORD" \
        --data-urlencode "password_confirm=$PRISMARR_ADMIN_PASSWORD" "$URL/setup/admin")
    echo "prismarr: admin step answered $c for $PRISMARR_ADMIN_EMAIL"
fi

# /setup/finish renders (200) until setup_completed=1; the optional service
# steps are left for the admin under Settings.
if [ "$(code "$URL/setup/finish")" = 200 ]; then
    t=$(token /setup/finish)
    c=$(code --data-urlencode "_csrf_token=$t" "$URL/setup/finish")
    echo "prismarr: finish step answered $c"
else
    echo "prismarr: setup already complete"
fi

for p in /setup/admin /setup/finish /setup/managers; do
    [ "$(code "$URL$p")" = 302 ] || { echo "prismarr: $p is still open" >&2; exit 1; }
done
