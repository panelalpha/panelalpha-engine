#!/bin/sh
# The container's entrypoint: wraps the image's own entrypoint.sh (it renders
# the frontend, installs or migrates the database, then execs its arguments).
set -e
if [ "${1:-}" = "--after-database" ]; then
    # The database is installed or migrated by now: the engine's login on the
    # built-in admin while it still has the published password, the vault's
    # key and the JWT secret, then Apache.
    shift
    php /panelalpha/admin-login.php \
        || echo "[panelalpha] passwordcockpit: could not set the admin login" >&2
    # The compose file's keys (supersecretkey, authenticationsecretkey) are
    # the same on every install. vault-key.php keeps the published cipher key
    # only for a vault already encrypted with it; it fails the start rather
    # than encrypt with a second key.
    key="$(php /panelalpha/vault-key.php)"
    export PASSWORDCOCKPIT_BLOCK_CIPHER_KEY="${key}"
    if [ -n "${PASSWORDCOCKPIT_JWT_SECRET:-}" ]; then
        export PASSWORDCOCKPIT_AUTHENTICATION_SECRET_KEY="${PASSWORDCOCKPIT_JWT_SECRET}"
    fi
    exec "$@"
fi
# The frontend is built with PASSWORDCOCKPIT_BASEHOST as the API's address and
# entrypoint.sh writes it into public/ on every start. The repository's compose
# file says http://localhost:8080, which in a visitor's browser is their own
# machine: every API call, the login included, went nowhere. The site's public
# address instead (PA_PUBLIC_URL, set by the engine).
if [ -n "${PA_PUBLIC_URL:-}" ]; then
    export PASSWORDCOCKPIT_BASEHOST="${PA_PUBLIC_URL}"
fi
exec entrypoint.sh /bin/sh /panelalpha/start.sh --after-database apache2-foreground
