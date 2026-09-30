#!/bin/bash
# Completes Snipe-IT's own setup wizard (first visitor wins) with the generated
# admin from ~/.panelalpha/snipeit/admin.env. A no-op once setup is complete.
set -euo pipefail

BASE="http://app:80"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
COOKIE=""

# Session cookies are Secure (SECURE_COOKIES) and curl drops Secure cookies
# received over http, so they are carried from Set-Cookie by hand.
# Body goes to $TMP/body, headers to $TMP/hdr.
req() {
    curl -s --max-time 300 -D "$TMP/hdr" -o "$TMP/body" -H "Cookie: $COOKIE" "$@"
    local c
    c=$(sed -n 's/^[Ss]et-[Cc]ookie: *\([^;]*\).*/\1/p' "$TMP/hdr" | paste -sd ';' -)
    if [ -n "$c" ]; then COOKIE="$c"; fi
}
status() { sed -n '1s/^HTTP[^ ]* \([0-9]*\).*/\1/p' "$TMP/hdr"; }
location() { sed -n 's/^[Ll]ocation: *\([^[:space:]]*\).*/\1/p' "$TMP/hdr"; }

req "$BASE/setup/user"
if [ "$(status)" = "302" ]; then
    echo "Snipe-IT setup already completed"
    exit 0
fi
[ "$(status)" = "200" ] || { echo "unexpected /setup/user status $(status)" >&2; exit 1; }
tok=$(sed -n 's/.*name="_token"[^>]*value="\([^"]*\)".*/\1/p' "$TMP/body" | head -1)
[ -n "$tok" ] || { echo "no CSRF token on /setup/user" >&2; exit 1; }

# Wizard step 2: migrations plus the Passport keys the API needs.
req "$BASE/setup/migrate" -X POST --data-urlencode "_token=$tok"
[ "$(status)" = "200" ] || { echo "setup/migrate answered $(status)" >&2; exit 1; }

# Step 3: the first admin and the settings row; either missing keeps /setup open.
req "$BASE/setup/user" -X POST \
    --data-urlencode "_token=$tok" \
    --data-urlencode "site_name=Snipe-IT" \
    --data-urlencode "first_name=Admin" \
    --data-urlencode "last_name=User" \
    --data-urlencode "username=${SNIPEIT_ADMIN_USER}" \
    --data-urlencode "email=${SNIPEIT_ADMIN_EMAIL}" \
    --data-urlencode "password=${SNIPEIT_ADMIN_PASSWORD}" \
    --data-urlencode "password_confirmation=${SNIPEIT_ADMIN_PASSWORD}" \
    --data-urlencode "locale=en-US" \
    --data-urlencode "default_currency=USD"
case "$(location)" in
    */setup/done) echo "Snipe-IT admin ${SNIPEIT_ADMIN_USER} created" ;;
    *) echo "setup/user did not complete (redirect: $(location))" >&2; exit 1 ;;
esac

COOKIE=""
req "$BASE/setup/user"
[ "$(status)" = "302" ] || { echo "setup still open after seeding ($(status))" >&2; exit 1; }
