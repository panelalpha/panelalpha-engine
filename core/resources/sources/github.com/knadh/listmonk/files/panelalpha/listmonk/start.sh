#!/bin/sh
# Replaces upstream's command -- install, upgrade, serve -- with the same three
# steps plus one: the Super Admin exists before listmonk listens on its
# published port, so the "fresh install, pick a username and password" page is
# never served to whoever opens the site first.
set -e
cd /listmonk

# A new database: listmonk's own install creates the Super Admin from these.
LISTMONK_ADMIN_USER="${PA_ADMIN_USER}" LISTMONK_ADMIN_PASSWORD="${PA_ADMIN_PASSWORD}" \
    ./listmonk --install --idempotent --yes --config ''
./listmonk --upgrade --yes --config ''

# A database installed before this recipe set an admin has no user at all, and
# --idempotent leaves it that way. Claim it through listmonk's own first-time
# setup form, on a loopback address nothing outside this container can reach.
# Any database that already has a user is left alone.
PORT=127.0.0.1:9099
LISTMONK_app__address="${PORT}" ./listmonk --config '' &
pid=$!
page=""
i=0
while [ "$i" -lt 60 ]; do
    page=$(wget -q -O - "http://${PORT}/admin/login" 2>/dev/null) && break
    i=$((i + 1))
    sleep 1
done
if [ -z "${page}" ]; then
    echo "[panelalpha] listmonk: did not answer on ${PORT}; not starting unclaimed" >&2
    kill "${pid}" 2>/dev/null || true
    exit 1
fi
if printf '%s' "${page}" | grep -q 'name="password2"'; then
    wget -q -O /dev/null --post-data="email=${PA_ADMIN_USER}%40listmonk&username=${PA_ADMIN_USER}&password=${PA_ADMIN_PASSWORD}&password2=${PA_ADMIN_PASSWORD}" \
        "http://${PORT}/admin/login" 2>/dev/null || true
    if wget -q -O - "http://${PORT}/admin/login" 2>/dev/null | grep -q 'name="password2"'; then
        echo "[panelalpha] listmonk: could not create the Super Admin; not starting unclaimed" >&2
        kill "${pid}" 2>/dev/null || true
        exit 1
    fi
    echo "[panelalpha] listmonk: created Super Admin '${PA_ADMIN_USER}' on an existing database"
fi
kill "${pid}" 2>/dev/null || true
wait "${pid}" 2>/dev/null || true

unset PA_ADMIN_USER PA_ADMIN_PASSWORD
exec ./listmonk --config ''
