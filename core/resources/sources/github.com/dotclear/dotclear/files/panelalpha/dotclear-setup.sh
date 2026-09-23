#!/bin/sh
# PanelAlpha setup for Dotclear (github.com/dotclear/dotclear (official mirror of git.dotclear.org)).
#
# Runs before Apache on every boot (start stage, before: true). It keeps the
# config, master key and media outside the per-deploy wipe of ~/project, and on
# the first boot seeds inc/config.php and the super-admin over Dotclear's own
# CLI installer so the web wizard never opens to a visitor. Idempotent: once
# the persistent config.php exists, only the symlinks are (re)made.
#
# Persistence lives on /pa-data, which the recipe's docker-compose.override.yml
# bind-mounts from ~/.panelalpha (the account home survives a redeploy; ~/project
# does not -- engine#173). HOME is not set in the app container, so absolute
# paths only.
set -eu

APP_URL="${APP_URL:-}"
DB_HOST="${DB_HOST:-}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-}"
DB_USERNAME="${DB_USERNAME:-}"
DB_PASSWORD="${DB_PASSWORD:-}"

PA_DATA="/pa-data/dotclear"
CONF="${PA_DATA}/config.php"
CREDS="${PA_DATA}/admin-credentials.txt"

if [ ! -d /pa-data ]; then
    echo "panelalpha/dotclear: /pa-data is not mounted; the compose override did not apply" >&2
    exit 1
fi

mkdir -p "${PA_DATA}/public" "${PA_DATA}/cache" "${PA_DATA}/var"

# The repo ships no public/; media, the template cache and var must outlive the
# redeploy that empties ~/project, so they live on /pa-data and are linked back
# in. public/ carries no index, so PhpDocroot still resolves the root.
ln -sfn "${PA_DATA}/public" /app/public
ln -sfn "${PA_DATA}/cache"  /app/cache
ln -sfn "${PA_DATA}/var"    /app/var

# Never serve the clone's VCS metadata.
rm -rf /app/.git

if [ ! -f "${CONF}" ]; then
    BASEURL="${APP_URL%/}"
    case "${BASEURL}" in
        http://*|https://*) : ;;
        *) echo "panelalpha/dotclear: APP_URL is not an http(s) URL ('${APP_URL}'); cannot install" >&2; exit 1 ;;
    esac
    MAILDOM="${BASEURL#*://}"; MAILDOM="${MAILDOM%%/*}"; MAILDOM="${MAILDOM%%:*}"

    # Pick the best MySQL driver whose PHP extension is actually present.
    DRIVER="$(php -r '$m=["mysqlimb4"=>"mysqli","pdomysqlmb4"=>"pdo_mysql","mysqli"=>"mysqli","pdomysql"=>"pdo_mysql"];foreach($m as $d=>$e){if(extension_loaded($e)){echo $d;exit;}}')"
    if [ -z "${DRIVER}" ]; then
        echo "panelalpha/dotclear: neither mysqli nor pdo_mysql is in the image" >&2; exit 1
    fi

    ADMPW="$(php -r 'echo bin2hex(random_bytes(12));')"

    # Steer Dotclear's config path to the persistent file. DC_RC_PATH is read
    # from $_SERVER when Config is constructed (early), so it must be a real
    # environment variable -- the installer's own argv[1] handling sets it too
    # late (Install\Utility::init() runs after Config has already resolved the
    # default inc/config.php), which is why passing it only as argv[1] wrote to
    # the wrong place.
    export DC_RC_PATH="${CONF}"

    # Step 1 - write config.php (DB DSN + generated master key) at the
    # persistent path. Values are fed to the interactive prompts over stdin, in
    # prompt order: driver, host, name, user, password, table prefix, admin
    # mail, admin URL. (argv[1] repeats DC_RC_PATH and, being a leading
    # positional, also forces getopt into the interactive branch we rely on.)
    printf '%s\n' \
        "${DRIVER}" \
        "${DB_HOST}:${DB_PORT}" \
        "${DB_DATABASE}" \
        "${DB_USERNAME}" \
        "${DB_PASSWORD}" \
        "dc_" \
        "admin@${MAILDOM}" \
        "${BASEURL}/admin" \
        | php /app/admin/install/index.php "${CONF}"

    # Step 2 - schema, super-admin, default blog, first post. Prompt order:
    # first name, last name, mail, login, password, password confirm, blog URL.
    printf '%s\n' \
        "" \
        "Administrator" \
        "admin@${MAILDOM}" \
        "admin" \
        "${ADMPW}" \
        "${ADMPW}" \
        "${BASEURL}" \
        | php /app/admin/install/index.php "${CONF}"

    umask 077
    printf 'url:      %s/admin\nlogin:    admin\npassword: %s\n' "${BASEURL}" "${ADMPW}" > "${CREDS}"
    echo "panelalpha/dotclear: installed with driver ${DRIVER}; admin credentials at ~/.panelalpha/dotclear/admin-credentials.txt" >&2
fi

# config.php lives outside ~/project; point Dotclear's default lookup at it.
ln -sfn "${CONF}" /app/inc/config.php
