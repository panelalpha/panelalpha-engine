#!/bin/sh
# One-shot, run to completion before the `ready` gate lets `docker compose up`
# return. It claims the workspace owner over Ryot's own GraphQL API.
#
# Why this exists. Ryot's registerUser mutation makes the FIRST user it creates
# an admin (crates/services/user register_user: total_users == 0 => Admin). With
# USERS_ALLOW_REGISTRATION=false the public sign-up page is closed, and the
# mutation only proceeds for a caller whose admin_access_token matches
# SERVER_ADMIN_ACCESS_TOKEN. So this script -- on the internal network, holding
# that token -- is the only thing that can create the owner, and it does so with
# a password generated once into ~/.panelalpha/ryot/. There is never a window in
# which a stranger can register and become the admin.
#
# Idempotent: on a redeploy the owner already exists in the persisted database,
# a login with the stored password succeeds, and it exits without touching
# anything. Exiting non-zero fails the deploy (the `ready` gate depends on this
# with service_completed_successfully), so a database that never came up or an
# owner that could not be created stops the stack rather than publishing a site
# with no working credentials.
set -u

say() { echo "[panelalpha/init] $*"; }

# Ryot's GraphQL endpoint, reached through Caddy on the app container. Caddy
# strips the /backend prefix and reverse-proxies to the Rust backend.
API="http://app:8000/backend/graphql"
H="Content-Type: application/json"

# Second belt on top of the compose healthcheck: wait until the backend answers
# /backend/config (plain JSON, 200 only once the Rust backend is up).
attempt=0
until curl -fsS --max-time 10 "http://app:8000/backend/config" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 60 ]; then
        say "backend never became ready"
        exit 1
    fi
    sleep 3
done
say "backend is up"

# Also confirm the frontend (react-router-serve behind Caddy) actually serves,
# not just the backend -- a boot with a broken frontend should fail the deploy.
# The site root `/` 302-redirects to the auth page, so probe /auth, which the
# frontend renders with 200.
attempt=0
until [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://app:8000/auth")" = "200" ]; do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 40 ]; then
        say "frontend never served 200 on /auth"
        exit 1
    fi
    sleep 3
done
say "frontend is serving"

login_body() {
    printf '{"query":"mutation($i:AuthUserInput!){loginUser(input:$i){__typename ... on ApiKeyResponse{apiKey} ... on LoginError{error}}}","variables":{"i":{"password":{"username":"%s","password":"%s"}}}}' \
        "${RYOT_OWNER_USERNAME}" "${RYOT_OWNER_PASSWORD}"
}

# Already provisioned? (redeploy on the persisted database). A successful login
# returns an ApiKeyResponse.
resp="$(curl -s --max-time 20 -X POST "${API}" -H "${H}" -d "$(login_body)")"
case "${resp}" in
    *'"apiKey"'*)
        say "owner already provisioned; login succeeded"
        exit 0 ;;
esac
say "owner login did not succeed yet, registering it"

# First run: register the owner. registerUser bypasses the disabled-registration
# guard because admin_access_token matches SERVER_ADMIN_ACCESS_TOKEN, and the
# first user is created as an admin.
reg="$(printf '{"query":"mutation($i:RegisterUserInput!){registerUser(input:$i){__typename ... on StringIdObject{id} ... on RegisterError{error}}}","variables":{"i":{"data":{"password":{"username":"%s","password":"%s"}},"adminAccessToken":"%s"}}}' \
    "${RYOT_OWNER_USERNAME}" "${RYOT_OWNER_PASSWORD}" "${SERVER_ADMIN_ACCESS_TOKEN}")"
out="$(curl -s --max-time 30 -X POST "${API}" -H "${H}" -d "${reg}")"
case "${out}" in
    *'"StringIdObject"'*|*'IdentifierAlreadyExists'*)
        say "owner registered (or already existed): ${out}" ;;
    *)
        say "could not register owner: ${out}"
        exit 1 ;;
esac

# Prove the owner can log in with the stored password before finishing.
resp="$(curl -s --max-time 20 -X POST "${API}" -H "${H}" -d "$(login_body)")"
case "${resp}" in
    *'"apiKey"'*)
        say "owner login verified"
        exit 0 ;;
    *)
        say "owner registered but login failed: ${resp}"
        exit 1 ;;
esac
