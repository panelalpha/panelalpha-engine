#!/bin/sh
# Beszel's USER_EMAIL seeding creates the first user with role "user"; its own
# first-run form would make it "admin". Promote it through the API, as the
# superuser, only while it is still the one and only user.
set -u
H=http://app:8090

login=$(printf '{"identity":"%s","password":"%s"}' "$USER_EMAIL" "$USER_PASSWORD")
tok=$(curl -fsS -H 'Content-Type: application/json' -d "$login" "$H/api/collections/_superusers/auth-with-password" \
    | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$tok" ] || { echo "superuser login failed (password changed?); roles left alone"; exit 0; }

list=$(curl -fsS -H "Authorization: $tok" "$H/api/collections/users/records?perPage=2")
case "$list" in *'"totalItems":1,'*) ;; *) echo "not a fresh instance; roles left alone"; exit 0 ;; esac
case "$list" in *'"role":"user"'*) ;; *) echo "first user already has its role"; exit 0 ;; esac
id=$(echo "$list" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')

curl -fsS -X PATCH -H "Authorization: $tok" -H 'Content-Type: application/json' -d '{"role":"admin"}' \
    "$H/api/collections/users/records/$id" | grep -q '"role":"admin"' \
    && echo "first user promoted to admin" || { echo "promotion failed"; exit 1; }
