#!/bin/bash
# Account shell, after the clone and before the build. Runs on every deploy.
#
# Four jobs, none of which the checkout can do for itself:
#
#   1. vendor AWL, without which not one request reaches PHP's parser
#   2. generate the two secrets, outside ~/project so they survive a redeploy
#   3. write config/config.php, which the repository .gitignore's and always.php
#      exits without
#   4. put an .htaccess in the document root over setup.php
#
# Nothing here can see the database: the postgres sidecar this recipe adds is
# started by `docker compose up` long after this hook has finished, so
# everything that needs a connection lives in panelalpha-davical-setup.php,
# which runs inside the container on the install/upgrade stages.
set -e
cd ~/project

STORE="${HOME}/.panelalpha"
# Account homes are root-owned 755, so this directory has to be created rather
# than written into $HOME directly, and it is the only place a generated secret
# survives a deploy: GitRepository::cloneConfiguredRepository empties ~/project
# before every clone (engine #173), while the postgres volume does not.
mkdir -p "${STORE}"
chmod 700 "${STORE}"

# ---------------------------------------------------------------------------
# 1. Andrew's Web Libraries.
#
# DAViCal is half an application. htdocs/always.php is the first line of every
# entry point -- admin.php, caldav.php, index.php, public.php, feed.php,
# freebusy.php -- and its first real act is
#
#   if ( ! @include_once('AWLUtilities.php') ) { ...four hard-coded paths...
#     echo "Could not find the AWL libraries. Are they installed?"; exit(0); }
#
# AWL is a separate repository (gitlab.com/davical-project/awl) that upstream
# expects a distribution package to have put in /usr/share/awl. The engine
# clones one repository and bind-mounts it at /app, so there is nothing to
# satisfy that include and no second `git:` key in a manifest to ask for one.
# Hence this hook, and files/panelalpha/php/zz-davical.ini, which puts
# /app/awl/inc on the include_path so the first `include_once` succeeds rather
# than falling through to paths that cannot exist here.
#
# Pinned, and pinned to a tag rather than to the branch tip: always.php sets
# $c->want_awl_version = '0.65' and setup.php's dependency table compares it
# against awl_version() -- so an AWL that moves on its own is a version
# mismatch the account owner is shown and cannot act on. r0.65 is the tag
# matching this DAViCal (VERSION 1.1.13), and is what debian/control pins too:
# `libawl-php (>= 0.65-1~), libawl-php (<< 0.66)`.
AWL_TAG="r0.65"
AWL_CACHE="${STORE}/awl-${AWL_TAG}"

# Cached outside ~/project, cloned once. ~/project is wiped and re-cloned on
# every deploy (engine #173) and copying 4 MB from the account's own disk beats
# a network round trip to gitlab.com on every redeploy -- and means a redeploy
# still works when gitlab.com does not.
if [ ! -f "${AWL_CACHE}/inc/AWLUtilities.php" ]; then
    rm -rf "${AWL_CACHE}" "${AWL_CACHE}.tmp"
    echo "[davical] fetching AWL ${AWL_TAG}"
    git clone --depth 1 --branch "${AWL_TAG}" \
        https://gitlab.com/davical-project/awl.git "${AWL_CACHE}.tmp"
    # .git is 3 MB of history for a pinned tag nobody will check out again, and
    # it would land inside the bind mount below.
    rm -rf "${AWL_CACHE}.tmp/.git"
    mv "${AWL_CACHE}.tmp" "${AWL_CACHE}"
fi

# Into the checkout, because the container only ever sees ~/project: the
# generated compose bind-mounts it at /app and nothing else of the account's
# home is visible inside. A symlink to ~/.panelalpha would dangle.
#
# awl/ is a sibling of htdocs/, which is the document root, so none of it is
# web-reachable -- the same reason inc/, dba/ and config/ are safe here.
rm -rf awl
cp -a "${AWL_CACHE}" awl

# ---------------------------------------------------------------------------
# 2. Secrets, generated once per account and kept out of the checkout.
#
# The master copy lives in ~/.panelalpha because ~/project is emptied and
# re-cloned on every deploy while the postgres volume is not: a password
# regenerated on redeploy is a database the application can no longer open.
#
# From here the two secrets travel by different routes, and the difference is
# forced rather than chosen:
#
#   DAVICAL_DB_PASS / DAVICAL_ADMIN_PASS -> ~/.panelalpha/davical-app.env,
#       loaded by the app service with `env_file:` at a path that climbs out of
#       the document root. Nothing else sets those two keys, so nothing
#       outranks the file.
#
#   PA_DAVICAL_DB_PASSWORD -> ~/project/.env, where `docker compose` reads
#       variables for `${...}` interpolation. The postgres service cannot use
#       env_file: RuntimeSidecars mines docker-compose.override.yml and writes
#       `POSTGRES_PASSWORD: app` into the generated compose as `environment:`
#       (engine #166, #189), which outranks any env_file. The override's own
#       `environment:` is the only thing that wins that merge, and an
#       `environment:` value has to come from a compose variable.
#
# ProjectEnvironment::apply() then copies .env to .env.default at mode 644
# (engine #173), so the database password is in the account's home twice. Both
# are dotfiles, which the generated vhost denies outright
# (apache-vhost.stub's `FilesMatch "^\.(?!well-known)"`) -- and both are
# outside the document root here anyway, which is htdocs/. The admin password,
# a login credential rather than an internal one, is kept out of .env for that
# reason.
DB_ENV="${STORE}/davical-db.env"
APP_ENV="${STORE}/davical-app.env"

# One missing and one present is not a state to paper over: regenerating the
# pair would mint a new database password for a volume that still holds the old
# one, and the account would come back up unable to open its own data. Say so
# and stop, while the deploy can still be read as having failed.
if { [ -f "${DB_ENV}" ] && [ ! -f "${APP_ENV}" ]; } \
   || { [ ! -f "${DB_ENV}" ] && [ -f "${APP_ENV}" ]; }; then
    echo "[davical] ${STORE} holds one of davical-db.env / davical-app.env but" >&2
    echo "[davical] not the other. Restore the missing file or delete both" >&2
    echo "[davical] (which also means deleting the postgres volume)." >&2
    exit 1
fi

# The umask is inside a subshell on purpose -- it has to cover the redirections
# that create these files, and it must not leak into the rest of this script,
# where a 077 default would leave a checkout the engine cannot walk.
if [ ! -f "${DB_ENV}" ]; then
  (
    umask 077
    DB_PASS=$(openssl rand -hex 24)
    # A-Za-z0-9 only: this password is typed into DAViCal's login form and,
    # more to the point, sent by a CalDAV client in an HTTP Basic header where
    # a stray ':' would be ambiguous.
    ADMIN_PASS=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-20)

    cat > "${DB_ENV}" <<EOF
# Generated by the PanelAlpha DAViCal recipe. The master copy of the database
# password; the working copy is appended to ~/project/.env on every deploy for
# compose to interpolate into the postgres service.
PA_DAVICAL_DB_PASSWORD=${DB_PASS}
EOF

    cat > "${APP_ENV}" <<EOF
# Generated by the PanelAlpha DAViCal recipe. Read by the app container.
# The same password as POSTGRES_PASSWORD in davical-db.env; the database volume
# outlives the checkout, so regenerating it on a redeploy would lock the
# application out of its own data.
DAVICAL_DB_PASS=${DB_PASS}
# The built-in admin account. dba/base-data.sql seeds 'admin' with the
# plaintext password '**nimda'; panelalpha-davical-setup.php replaces it with
# this on the install stage.
DAVICAL_ADMIN_PASS=${ADMIN_PASS}
EOF
  )
fi
chmod 600 "${DB_ENV}" "${APP_ENV}"

# The working copy, for compose's `${...}` interpolation. Removed and re-added
# rather than appended blindly: .env is recreated from the clone on every
# deploy, but a restored backup may already carry a line, and two definitions
# of the same key in one file is a coin toss.
touch .env
sed -i -E '/^[[:space:]]*PA_DAVICAL_DB_PASSWORD=/d' .env
{
    echo ""
    echo "# --- PanelAlpha DAViCal: read by docker compose, not by the app ---"
    cat "${DB_ENV}"
} >> .env
chmod 600 .env

# A copy the account owner can actually find. Same directory, same mode; this
# is what the deploy log tells them to cat.
cat > "${STORE}/davical-admin-credentials.txt" <<EOF
DAViCal - built-in administrator
  admin UI:  https://<your domain>/
  CalDAV:    https://<your domain>/caldav.php/admin/calendar/
  login:     admin
  password:  $(sed -n 's/^DAVICAL_ADMIN_PASS=//p' "${APP_ENV}")

Generated once per account by the PanelAlpha DAViCal recipe, replacing the
plaintext 'nimda' that dba/base-data.sql seeds. Change it in the admin UI and
this file stops being true.
EOF
chmod 600 "${STORE}/davical-admin-credentials.txt"

# ---------------------------------------------------------------------------
# 3. The configuration file.
#
# htdocs/always.php looks for its configuration in six places, in order:
# /etc/davical/<SERVER_NAME>-conf.php, /etc/davical/config.php, the same two
# under /usr/local/etc/, then ../config/config.php and config/config.php
# relative to the working directory. The working directory is the document
# root, so ../config/config.php is this file -- the only one of the six an
# account can write. Find none of them and always.php includes
# davical_configuration_missing.php and exits, for every request.
#
# The repository .gitignore's config/config.php and ships config/
# example-config.php instead, so a clone never has one: this is the file the
# engine has no way to infer and the whole reason a `serving-missing_entry`
# verdict would not have been fixed by the docroot alone.
#
# It holds no secret. Every value that is one is read from the container's
# environment at request time -- the same posture as the zentao recipe's
# config/my.php -- so the database password is not written to the account's
# disk in a file the application reads. config/ is a sibling of htdocs/ and so
# is not web-reachable either way; this is belt and braces, and it means the
# file can be read by anyone debugging the account without leaking anything.
mkdir -p config
cat > config/config.php <<'EOF'
<?php
/**
 * Written by the PanelAlpha DAViCal recipe on every deploy. Edits are lost.
 *
 * Found by htdocs/always.php as '../config/config.php', relative to the
 * document root it is included from. Outside htdocs/, so not web-reachable.
 */

// The database, from the container's environment rather than from this file.
// The array form of pg_connect is used deliberately: AWL's
// _awl_connect_configured_database() also accepts a libpq-style string, but it
// parses one with a greedy regex ('/^(\S+:)?(.*)( user=(\S+))?( password=(\S+))?$/',
// awl/inc/AwlQuery.php:63) whose (.*) swallows the user= and password= it is
// meant to split off. The array skips the parse entirely.
$c->pg_connect[] = array(
    'dsn' => sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('DAVICAL_DB_HOST'),
        getenv('DAVICAL_DB_PORT') ?: '5432',
        getenv('DAVICAL_DB_NAME')
    ),
    'dbuser' => getenv('DAVICAL_DB_USER'),
    'dbpass' => getenv('DAVICAL_DB_PASS'),
);

// Shown on the login page and used as the From: of anything DAViCal sends.
$c->admin_email = getenv('DAVICAL_ADMIN_EMAIL') ?: 'admin@' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
$c->system_name = 'DAViCal CalDAV Server';

// $c->domain_name is deliberately left at always.php's own default, which is
// $_SERVER['SERVER_NAME']. Hard-coding it would tie the account to one domain,
// and an account may have several pointed at it; DAViCal only uses the value
// to build $c->protocol_server_port, and every href it emits is made relative
// by ConstructURL() before it is written into a multistatus.

// TLS terminates at the engine's proxy and the container sees plain HTTP. The
// base image's auto_prepend_file (panelalpha-proxy.php) already restores
// $_SERVER['HTTPS'] from the forwarded headers before any application code
// runs, so this is here for DAViCal's own reading of X-Forwarded-* -- the
// scheme and port that end up in $c->protocol_server_port.
$c->trust_x_forwarded = true;

// htdocs/setup.php embeds the complete output of phpinfo() in its page
// (setup.php:497). Without this it is gated by LoginRequired(null), which is
// *any* authenticated principal rather than an administrator -- so every
// calendar user this account ever creates could read the account's paths,
// extensions and environment. It is also denied outright in htdocs/.htaccess;
// this is the same rule said in the application, so that the protection does
// not rest on a single file an operator might remove.
$c->restrict_setup_to_admin = true;

// Off by default in always.php and restated. `true` would have DAViCal send
// scheduling mail and fetch iSchedule from remote hosts on a PUT, which is
// outbound traffic an account has not asked for.
$c->enable_scheduling = false;

// Metrics are off unless asked for: htdocs/metrics.php answers before any
// authentication and $c->metrics_style is what decides whether it answers with
// principal, collection and resource counts or with "Metrics are not enabled."
// Unset is already off; said here so that turning it on is a decision.
// $c->metrics_style = 'prometheus';
// $c->metrics_require_user = 'metrics';

// Calendar clients that cannot MKCALENDAR (Evolution, Lightning) need their
// collections to exist before they write. always.php's default is false, which
// is upstream's; left alone so that a collection is a real row an administrator
// created rather than one conjured by the first PUT.
EOF
chmod 644 config/config.php

# administration.yml is not written. It is read only by
# dba/update-davical-database, the Perl program this deployment does not run
# (panelalpha-davical-setup.php replaces it), and it is the one file upstream
# puts a database superuser password into. Not creating it is the point.

# ---------------------------------------------------------------------------
# 4. The document root.
#
# htdocs/ is DAViCal's document root and everything in it is meant to be
# served -- except setup.php, and except the two path prefixes that clients
# expect to find at the top of a CalDAV host.
cat > htdocs/.htaccess <<'EOF'
# Installed by the PanelAlpha DAViCal recipe.

# setup.php renders phpinfo() into the page (htdocs/setup.php:497). Its own
# gate is $session->LoginRequired(null) -- any principal, not an administrator
# -- unless $c->restrict_setup_to_admin is set, which config/config.php does
# set. But the gate is inside a try/catch whose handler installs a
# `setupFakeSession` returning true from AllowedTo() (setup.php:215-222), and
# what lands in that catch is always.php failing -- which is what a database
# that has not finished starting looks like. So the one state in which this
# page is unauthenticated is the one in which something is already wrong.
# Denied outright: it is a diagnostic page, and nothing an account owner needs
# is only in it.
<FilesMatch "^setup\.php$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>

# The admin session cookie, with the two flags AWL does not set.
#
# AWL writes it with the four-argument form of setcookie --
# `setcookie('sid', $sid, 0, '/')` (awl/inc/Session.php:461) -- which cannot
# express httponly or samesite, and it is not a PHP session cookie, so
# session.cookie_httponly in the ini does not reach it. Measured: the cookie
# arrives with no flags at all, readable by any script on the page.
#
# mod_headers is enabled in the shared base image
# (PhpApacheConfig::MODULES = ['rewrite', 'headers']), and rewriting the header
# on the way out is the only place to fix this without patching AWL.
#
# `Secure` is deliberately not added, for the same reason
# session.cookie_secure is left alone in zz-davical.ini: TLS terminates at the
# engine's proxy, so an account also reachable over http:// would get a login
# form that never logs anyone in and no error to explain it.
#
# SameSite=Lax is defence in depth rather than the primary control -- DAViCal's
# admin forms already carry a csrf_token (inc/csrf_tokens.php) -- and it is safe
# for CalDAV, which authenticates with HTTP Basic and never sends this cookie.
# `edit`, not `always edit`. The two operate on different header tables:
# `always` is Apache's err_headers_out, and PHP's setcookie() lands in
# headers_out. Measured on a deployed account -- with `always` the cookie came
# back exactly as AWL wrote it, with no flags and no error anywhere; without
# it the flags are appended.
<IfModule mod_headers.c>
    Header edit Set-Cookie "^(sid=.*)$" "$1; HttpOnly; SameSite=Lax"
</IfModule>

# The discovery paths RFC 6764 says a CalDAV client may try before it has been
# told anything: /.well-known/caldav and /.well-known/carddav. DAViCal answers
# them from caldav.php (inc/well-known.php), and upstream's own
# config/apache-davical.conf carries these rules for a packaged install --
# where the application is under an Alias and these are the vhost's. Here the
# application *is* the document root, so they belong in its .htaccess.
#
# PT so the rewritten path is handed back to Apache as a URI rather than as a
# filename, which is what makes caldav.php's PATH_INFO survive. The engine's
# generated vhost already leaves .well-known reachable: its
# `DirectoryMatch "/\.(?!well-known)"` denial excludes exactly this prefix.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^\.well-known/(.*)$ /caldav.php/.well-known/$1 [NC,PT]
    RewriteRule ^principals/users/(.*)$ /caldav.php/$1 [NC,PT]
    RewriteRule ^principals/resources/(.*)$ /caldav.php/$1 [NC,PT]
    RewriteRule ^calendars/__uids__/(.*)$ /caldav.php/$1 [NC,PT]
    RewriteRule ^addressbooks/__uids__/(.*)$ /caldav.php/$1 [NC,PT]
</IfModule>
EOF
chmod 644 htdocs/.htaccess

# ---------------------------------------------------------------------------
# 5. Compose files the repository does not have today.
#
# DAViCal 1.1.13 ships no docker-compose.yml, no compose.yaml and no
# Dockerfile -- checked. Were one to appear, the php strategy would mine it for
# backing services (engine #166) and this account would grow whatever database
# upstream uses for its own CI. Moved by name and never by a
# `docker-compose.*.yml` glob, which matches this recipe's own override and
# would take the postgres sidecar and the healthcheck with it while the deploy
# still reported success.
mkdir -p "${STORE}/upstream-compose"
for f in docker-compose.yml docker-compose.yaml compose.yml compose.yaml; do
    [ -f "$f" ] || continue
    mv -f "$f" "${STORE}/upstream-compose/"
    echo "[davical] moved $f out of the checkout (engine #166)"
done

echo "[davical] prepare finished"
