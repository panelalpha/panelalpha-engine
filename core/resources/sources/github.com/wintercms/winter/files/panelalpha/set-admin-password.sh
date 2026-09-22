#!/bin/sh
# Give the seeded `admin` account a password somebody has, and record it.
#
# Backend\Database\Seeds\DatabaseSeeder creates user 1 as `admin` /
# admin@example.com with `Str::random(22)` and prints it:
#
#   The following password has been automatically generated for the "admin"
#   account: n3Jm6Rtq47GnxtSIbUX8RB
#
# That is a better default than most — the account is not open to whoever finds
# the address — but the only copy of it is in the deploy log, which is not
# where an account's credentials live. This replaces it with one written to
# ~/project/.panelalpha-admin-password before it is set, so there is never a
# password in the database that nothing recorded.
#
# Runs in the container, from /app, on the install and upgrade stages. It does
# nothing once the credentials file exists: rotate what upstream seeded, never
# what somebody chose afterwards in Settings → Administrators.
set -e

CRED=/app/.panelalpha-admin-password

if [ -f "$CRED" ]; then
    echo "[panelalpha] the admin password is already recorded; left alone" >&2
    exit 0
fi

# 20 base64url characters of CSPRNG. `php -r` rather than /dev/urandom and od
# because php is the one binary this script is guaranteed to have.
PASSWORD=$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(15)), "+/", "-_"), "=");')
if [ -z "$PASSWORD" ]; then
    echo "[panelalpha] could not generate a password; the admin account keeps the seeded one" >&2
    exit 1
fi

# The umask is inside the subshell so it covers the redirection that creates
# the file and does not leak into anything after it.
( umask 077; printf 'url: /backend\nlogin: admin\npassword: %s\n' "$PASSWORD" > "$CRED" )
chmod 600 "$CRED"

# Winter's own CLI, which hashes the password the same way the login form
# verifies it. `winter:passwd <user> <password>` is fully non-interactive and
# has no production guard; Backend\Console\UserCreate does, which is why this
# resets the seeded account rather than creating a second one.
if ! php artisan winter:passwd admin "$PASSWORD"; then
    # Leave nothing behind claiming a password that was never set, so the next
    # deploy tries again.
    rm -f "$CRED"
    echo "[panelalpha] winter:passwd failed; the admin password is still the one in the deploy log" >&2
    exit 1
fi

echo "[panelalpha] admin password written to ~/project/.panelalpha-admin-password" >&2
