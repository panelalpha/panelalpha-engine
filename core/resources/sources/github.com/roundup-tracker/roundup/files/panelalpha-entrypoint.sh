#!/bin/sh
set -e

TH="${TRACKER_HOME:-/data/tracker}"
PORT="${PORT:-8000}"
mkdir -p /data

# Roundup builds absolute links from [tracker] web and enforces that the request
# host matches it, so it must be the account's real HTTPS origin. The engine sets
# URL / PUBLIC_URL / APP_URL to that origin (ComposeHarden::urlEnvironment).
# Roundup requires a scheme and a trailing slash.
WEB="${PUBLIC_URL:-${URL:-${APP_URL:-}}}"
case "$WEB" in
    "") WEB="http://localhost:${PORT}/" ;;
    */) : ;;
    *)  WEB="${WEB}/" ;;
esac

if [ ! -f "$TH/config.ini" ]; then
    # First boot: create the tracker home. `install` writes config.ini + the
    # tracker's schema.py + html templates (sqlite backend, web URL, a dummy
    # mail host that is never contacted because SENDMAILDEBUG redirects mail to
    # a file). `initialise` then creates the database with the admin password.
    ADMINPW="$(python -c 'import secrets; print(secrets.token_urlsafe(18))')"
    roundup-admin -i "$TH" install classic sqlite \
        "tracker_web=${WEB},mail_domain=localhost,mail_host=localhost" </dev/null

    # Close open self-registration: the classic template grants the Anonymous
    # role the 'Register' permission in schema.py. Comment it out so a visitor
    # cannot create an account; anonymous keeps only read-only issue View.
    sed -i "s/^\(db\.security\.addPermissionToRole('Anonymous', 'Register', 'user')\)/#\1/" "$TH/schema.py"

    roundup-admin -i "$TH" initialise "$ADMINPW"

    # Surface the generated password to the operator. It lives in the tracker
    # home (not under the web-served html/ dir) and survives redeploys.
    umask 077
    printf '%s\n' "$ADMINPW" > "$TH/ADMIN_PASSWORD"
    echo "roundup: initialised tracker home at $TH (admin password in $TH/ADMIN_PASSWORD)"
else
    # Redeploy: keep every bit of data. Only refresh [tracker] web in case the
    # account's domain changed since the tracker was created.
    awk -v url="$WEB" '
        /^\[/ { sect = $0 }
        sect == "[tracker]" && /^web[ \t]*=/ { print "web = " url; next }
        { print }
    ' "$TH/config.ini" > "$TH/config.ini.new" && mv "$TH/config.ini.new" "$TH/config.ini"
    echo "roundup: reusing tracker home at $TH; [tracker] web set to $WEB"
fi

exec gunicorn \
    --bind "0.0.0.0:${PORT}" \
    --workers 2 \
    --timeout 120 \
    --access-logfile - \
    --error-logfile - \
    panelalpha_wsgiapp:app
