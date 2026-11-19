#!/bin/sh
# Give the seeded `admin` account the login the engine generated.
#
# Backend\Database\Seeds\DatabaseSeeder creates user 1 as `admin` /
# admin@example.com with `Str::random(22)` and prints it:
#
#   The following password has been automatically generated for the "admin"
#   account: n3Jm6Rtq47GnxtSIbUX8RB
#
# That is a better default than most - the account is not open to whoever finds
# the address - but the only copy of it is in the deploy log, which is not
# where an account's credentials live. This sets the login the engine generated
# instead (`credentials:` in panelalpha.yaml: WINTER_ADMIN_LOGIN and
# WINTER_ADMIN_PASSWORD, which the container gets from
# ~/.panelalpha/app-credentials.env and GET /projects/{name}/app-credentials
# returns), through Backend\Models\User exactly as `winter:passwd` does: the
# model hashes the password the way the login form verifies it.
#
# Runs in the container, from /app, on the install and upgrade stages. It does
# it once: /pa-data/admin-login records that it was done (no secret in it), and
# from then on a password somebody chose in Settings -> Administrators is never
# reset under them by a redeploy.
#
# An account set up before the engine owned the login has
# /pa-data/admin-password, the record an older version of this script wrote
# (`password: ...`). Its admin is moved to the engine's login only while it
# still has that recorded password; one changed since is left alone.
set -e

# /pa-data is ~/.panelalpha/winter: ~/project is emptied before every deploy.
MARK=/pa-data/admin-login
LEGACY=/pa-data/admin-password
if [ ! -f "$LEGACY" ] && [ -f /app/.panelalpha-admin-password ]; then
    LEGACY=/app/.panelalpha-admin-password
fi

if [ -f "$MARK" ]; then
    echo "[panelalpha] the admin login was set before; left alone" >&2
    exit 0
fi

if [ -z "${WINTER_ADMIN_PASSWORD:-}" ]; then
    echo "[panelalpha] no WINTER_ADMIN_PASSWORD from the engine; the admin password is still the one in the deploy log" >&2
    exit 1
fi

WINTER_LEGACY_PASSWORD=
if [ -f "$LEGACY" ]; then
    WINTER_LEGACY_PASSWORD=$(sed -n 's/^password: //p' "$LEGACY")
fi
export WINTER_LEGACY_PASSWORD

# Prints `set` or `kept`, anything else is a failure.
RESULT=$(php <<'PHP'
<?php
require '/app/bootstrap/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$login = getenv('WINTER_ADMIN_LOGIN') ?: 'admin';
$legacy = (string) getenv('WINTER_LEGACY_PASSWORD');
$user = Backend\Models\User::where('login', $login)->first()
    ?: Backend\Models\User::where('login', 'admin')->first();
if (! $user) {
    fwrite(STDERR, "[panelalpha] there is no seeded admin account\n");
    exit(1);
}
// The recorded password of an older deploy: still the admin's, or chosen since.
if ($legacy !== '' && ! $user->checkPassword($legacy)) {
    echo 'kept';
    exit(0);
}
$user->login = $login;
$user->password = getenv('WINTER_ADMIN_PASSWORD');
$user->forceSave();
echo 'set';
PHP
) || RESULT=

case "$RESULT" in
    *set)
        ( umask 077; printf 'login set by the engine (app-credentials), %s\n' "$(date -u +%FT%TZ)" > "$MARK" )
        # The older record names a password that no longer works.
        if [ -f "$LEGACY" ]; then rm -f "$LEGACY"; fi
        echo "[panelalpha] the admin's login is the one the engine generated" >&2
        ;;
    *kept)
        ( umask 077; printf 'login chosen before the engine owned it; left alone, %s\n' "$(date -u +%FT%TZ)" > "$MARK" )
        echo "[panelalpha] the admin password was changed after it was recorded; left alone" >&2
        ;;
    *)
        echo "[panelalpha] could not set the admin login; the admin password is still the one in the deploy log" >&2
        exit 1
        ;;
esac
