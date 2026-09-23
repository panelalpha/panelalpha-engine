#!/bin/bash
# SOGo's user directory is an SQL source -- a table this recipe creates and
# owns -- so user management is SQL, and the app container already carries the
# credentials and a `sogo-users` helper that does the hashing.
#
# No users:sso. SOGo authenticates every request with HTTP Basic or its own
# form and has no token login to mint, so there is nothing honest to return.
cd ~/project || exit 1

fail() { printf '{"error":%s}\n' "$(printf '%s' "$1" | sed 's/"/\\"/g;s/^/"/;s/$/"/')" >&2; exit 1; }

if [ "${1:-}" = 'info' ]; then
  echo '["users:list","users:add","users:delete","users:reset-password"]'
  exit 0
fi

# MISSING_SNIPPET is the engine's signal to reinstall files/ and retry -- the
# helper is baked into the image, so its absence means the image is older than
# this recipe.
docker compose exec -T app test -x /usr/local/bin/sogo-users 2>/dev/null \
  || { echo MISSING_SNIPPET >&2; exit 1; }

run() { docker compose exec -T app sogo-users "$@"; }

case "${1:-}" in
  users:list)
    run list | python3 -c '
import sys, json
out = []
for line in sys.stdin.read().splitlines():
    if not line.strip():
        continue
    uid, mail, cn = (line.split("\t") + ["", ""])[:3]
    out.append({"id": uid, "username": uid, "email": mail, "role": "user"})
print(json.dumps(out))'
    ;;

  users:add) # users:add <login> <email> <password> <role>
    [ -n "${2:-}" ] && [ -n "${4:-}" ] || fail "login and password are required"
    run add "$2" "${3:-}" "$4" >/dev/null || fail "could not create $2"
    printf '{"id":"%s"}\n' "$2"
    ;;

  users:delete) # users:delete <userId>  -- the c_uid
    [ -n "${2:-}" ] || fail "a user id is required"
    run delete "$2" >/dev/null || fail "could not delete $2"
    echo '{"success":true}'
    ;;

  users:reset-password) # users:reset-password <userId> <newPassword>
    [ -n "${2:-}" ] && [ -n "${3:-}" ] || fail "a user id and a password are required"
    run passwd "$2" "$3" >/dev/null || fail "could not reset the password for $2"
    echo '{"success":true}'
    ;;

  *)
    fail "unsupported command: ${1:-}"
    ;;
esac
