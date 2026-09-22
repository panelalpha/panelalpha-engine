#!/bin/sh
set -e

ENV="${TRAC_ENV:-/data/env}"
PORT="${PORT:-8000}"
REALM="trac"
PASSFILE="$ENV/admin.htdigest"
PWFILE="$ENV/ADMIN_PASSWORD"
mkdir -p /data

# Absolute links / redirects should use the account's real HTTPS origin. The
# engine sets URL / PUBLIC_URL / APP_URL to that origin (ComposeHarden::
# urlEnvironment). Trac's base_url takes no trailing slash.
WEB="${PUBLIC_URL:-${URL:-${APP_URL:-}}}"
case "$WEB" in
    "") WEB="http://localhost:${PORT}" ;;
    *) WEB="${WEB%/}" ;;
esac

if [ ! -f "$ENV/conf/trac.ini" ]; then
    # First boot: create the project environment. initenv writes conf/trac.ini,
    # the sqlite db and the wiki/attachment tree.
    trac-admin "$ENV" initenv "Trac" "sqlite:db/trac.db" </dev/null

    # Lock the environment down. The classic environment lets anonymous read
    # broadly; strip everything from anonymous and re-grant only read.
    for p in $(trac-admin "$ENV" permission list anonymous | awk '/^anonymous[ \t]/{print $2}'); do
        trac-admin "$ENV" permission remove anonymous "$p" >/dev/null
    done
    trac-admin "$ENV" permission add anonymous \
        WIKI_VIEW TICKET_VIEW MILESTONE_VIEW ROADMAP_VIEW \
        TIMELINE_VIEW SEARCH_VIEW REPORT_VIEW BROWSER_VIEW >/dev/null

    # The admin account. Only this account exists in the digest file, so only it
    # can authenticate; anonymous stays read-only.
    trac-admin "$ENV" permission add admin TRAC_ADMIN >/dev/null

    # Config: correct public base_url, and never touch SMTP from the web path.
    trac-admin "$ENV" config set trac base_url "$WEB" >/dev/null
    trac-admin "$ENV" config set trac use_base_url_for_redirect true >/dev/null
    trac-admin "$ENV" config set notification smtp_enabled false >/dev/null

    echo "trac: initialised environment at $ENV"
else
    # Redeploy: keep every bit of data; only refresh base_url in case the
    # account's domain changed since the environment was created.
    trac-admin "$ENV" config set trac base_url "$WEB" >/dev/null
    echo "trac: reusing environment at $ENV; base_url set to $WEB"
fi

# Admin password + digest file: generate once, persist 0600, reuse on redeploy.
if [ ! -f "$PASSFILE" ]; then
    umask 077
    ADMINPW="$(python -c 'import secrets; print(secrets.token_urlsafe(18))')"
    printf '%s\n' "$ADMINPW" > "$PWFILE"
    python - "$REALM" "$ADMINPW" > "$PASSFILE" <<'PY'
import hashlib, sys
realm, pw = sys.argv[1], sys.argv[2]
digest = hashlib.md5(("admin:%s:%s" % (realm, pw)).encode()).hexdigest()
print("admin:%s:%s" % (realm, digest))
PY
    echo "trac: generated admin password (in $PWFILE)"
fi

# tracd serves the single environment at / (-s), authenticating admin over HTTP
# Digest against the persistent digest file.
exec tracd -s \
    --port "$PORT" \
    --auth="*,$PASSFILE,$REALM" \
    "$ENV"
