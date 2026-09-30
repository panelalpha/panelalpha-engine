#!/bin/sh
# Seeds Wakapi's owner once. Runs after the app is healthy (migrations done).
# Env: WAKAPI_ADMIN_USER, WAKAPI_ADMIN_PASSWORD from ~/.panelalpha/wakapi/admin.env.
set -eu
DB=/data/wakapi.db

users=$(sqlite3 "$DB" 'SELECT COUNT(*) FROM users;')
if [ "$users" != "0" ]; then
    echo "wakapi: $users user(s) present, nothing to seed"
    exit 0
fi

# One-time invite code, in the shape Settings > Generate invite writes it:
# key "invite_<code>", value "<inviter>,<RFC3339 time>". Signup deletes it.
code=$(head -c 8 /dev/urandom | od -An -tx1 | tr -d ' \n')
now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
sqlite3 "$DB" "INSERT INTO key_string_values (\"key\", \"value\") VALUES ('invite_$code', 'panelalpha,$now');"

# Password is hex (prepare.sh), so it needs no URL encoding. Success is a 302
# that busybox wget re-POSTs to "/" (405), so judge by the users table instead.
wget -q -O /dev/null --post-data \
    "username=$WAKAPI_ADMIN_USER&email=&password=$WAKAPI_ADMIN_PASSWORD&password_repeat=$WAKAPI_ADMIN_PASSWORD&location=UTC&invite_code=$code" \
    http://app:3000/signup || true

admin=$(sqlite3 "$DB" "SELECT is_admin FROM users WHERE id = '$WAKAPI_ADMIN_USER';")
if [ "$admin" != "1" ]; then
    echo "wakapi: owner '$WAKAPI_ADMIN_USER' was not created as admin" >&2
    exit 1
fi
echo "wakapi: owner '$WAKAPI_ADMIN_USER' created"
