#!/bin/bash
# Account shell, after the clone and before the build. Runs on every deploy.
#
# Nothing here can see the database: the postgres sidecar this recipe adds is
# started by `docker compose up` long after this hook has finished, so
# everything that needs a connection lives in panelalpha-ttrss-setup.sh, which
# runs inside the container on the install/upgrade stages.
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
# 1. The repository's own compose file, out of the way.
#
# tt-rss ships docker-compose.yml at the root -- "simplified compose FOR LOCAL
# DEVELOPMENT", its own header says, with `image: ghcr.io/tt-rss/tt-rss:latest`,
# a bind mount of the source tree and a `db` service with
# POSTGRES_PASSWORD=password out of .env-dist. The php strategy does not run
# that file but does mine it for backing services (engine #166), so left in
# place it contributes a postgres whose password is published in the repository
# and three more containers nothing routes to.
#
# The glob covers docker-compose.*.yml as well, because the defect is about any
# compose file the miner can see, not the one filename. The override this
# recipe ships is excluded explicitly: whether it is written before or after
# this hook is not something a recipe should depend on, and moving it away
# would take the database with it.
mkdir -p "${STORE}/upstream-compose"
for f in docker-compose.yml docker-compose.yaml docker-compose.*.yml docker-compose.*.yaml compose.yml compose.yaml; do
    case "$f" in
        docker-compose.override.yml|docker-compose.override.yaml) continue ;;
    esac
    [ -f "$f" ] || continue
    mv -f "$f" "${STORE}/upstream-compose/"
    echo "[tt-rss] moved $f out of the checkout (engine #166)"
done

# The repository .gitignore's /.env and ships .env-dist, so a clone has no .env
# -- but a restored backup or an operator might. Compose reads it for
# interpolation and the generated compose loads it with `env_file:`, and
# .env-dist's own defaults are TTRSS_DB_USER=postgres / TTRSS_DB_PASS=password.
# Those become real environment variables at container creation and would
# outrank nothing, but they would sit in .env.default at 644 for no reason.
if [ -f .env ]; then
    sed -i -E '/^[[:space:]]*(TTRSS_DB_(USER|NAME|PASS|HOST|PORT)|HTTP_PORT|ADMIN_USER_PASS)=/d' .env
fi

# ---------------------------------------------------------------------------
# 2. Secrets, generated once per account and kept out of the checkout.
#
# The master copy lives here, outside ~/project, because ~/project is emptied
# and re-cloned on every deploy while the postgres volume is not: a password
# regenerated on redeploy is a database the application can no longer open.
#
# From here the two secrets travel by different routes, and the difference is
# forced rather than chosen:
#
#   TTRSS_DB_PASS / TTRSS_ADMIN_PASS  ->  ~/.panelalpha/tt-rss-app.env, loaded
#       by the app service with `env_file:` at a path that climbs out of the
#       document root. Nothing else sets those keys, so nothing outranks the
#       file. ComposeEnvFiles::projectRelative() refuses a `..` path, so the
#       engine never tries to create it on the stack's behalf -- this hook is
#       what guarantees it exists before `up`.
#
#   PA_TTRSS_DB_PASSWORD              ->  ~/project/.env, where `docker compose`
#       reads variables for `${...}` interpolation. The postgres service cannot
#       use env_file: RuntimeSidecars mines docker-compose.override.yml (its
#       `docker-compose.*.yml` glob matches it) and writes
#       `POSTGRES_PASSWORD: app` into the generated compose as `environment:`,
#       which outranks any env_file. The override's own `environment:` is the
#       only thing that wins that merge, and an `environment:` value has to
#       come from a compose variable.
#
# ProjectEnvironment::apply() then copies .env to .env.default at mode 644
# (engine #173), so that one password is in the account's home twice. Both are
# dotfiles, which the generated vhost denies outright (apache-vhost.stub's
# `FilesMatch "^\.(?!well-known)"`), so neither is web-readable -- but it is
# why the admin password, which is a login credential rather than an internal
# one, is kept out of .env.
DB_ENV="${STORE}/tt-rss-db.env"
APP_ENV="${STORE}/tt-rss-app.env"

# One missing and one present is not a state to paper over: regenerating the
# pair would mint a new database password for a volume that still holds the old
# one, and the account would come back up unable to open its own data. Say so
# and stop, while the deploy can still be read as having failed.
if { [ -f "${DB_ENV}" ] && [ ! -f "${APP_ENV}" ]; } \
   || { [ ! -f "${DB_ENV}" ] && [ -f "${APP_ENV}" ]; }; then
    echo "[tt-rss] ${STORE} holds one of tt-rss-db.env / tt-rss-app.env but not" >&2
    echo "[tt-rss] the other. Restore the missing file or delete both (which" >&2
    echo "[tt-rss] also means deleting the postgres volume)." >&2
    exit 1
fi

# The umask is inside a subshell on purpose -- it has to cover the redirections
# that create these files, and it must not leak into the rest of this script,
# where a 077 default would leave cache/ directories the engine cannot scan when
# it walks the tree for the document root.
if [ ! -f "${DB_ENV}" ]; then
  (
    umask 077
    DB_PASS=$(openssl rand -hex 24)
    # A-Za-z0-9 only: this password is typed into a login form and passed to
    # `update.php --user-set-password admin:<pw>`, which splits on the first
    # colon and would otherwise be at the mercy of whatever base64 produced.
    ADMIN_PASS=$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-20)

    cat > "${DB_ENV}" <<EOF
# Generated by the PanelAlpha tt-rss recipe. The master copy of the database
# password; the working copy is appended to ~/project/.env on every deploy for
# compose to interpolate into the postgres service.
PA_TTRSS_DB_PASSWORD=${DB_PASS}
EOF

    cat > "${APP_ENV}" <<EOF
# Generated by the PanelAlpha tt-rss recipe. Read by the app container.
# The same password as POSTGRES_PASSWORD in tt-rss-db.env; the database volume
# outlives the checkout, so regenerating it on a redeploy would lock the
# application out of its own data.
TTRSS_DB_PASS=${DB_PASS}
# The built-in admin account. tt-rss seeds 'admin' / 'password' in
# sql/pgsql/schema.sql and nags about it in the UI; panelalpha-ttrss-setup.sh
# replaces it with this on the install stage.
TTRSS_ADMIN_PASS=${ADMIN_PASS}
EOF
  )
fi
chmod 600 "${DB_ENV}" "${APP_ENV}"

# The working copy, for compose's `${...}` interpolation. Removed and re-added
# rather than appended blindly: .env is recreated from the clone on every
# deploy, but a restored backup may already carry a line, and two definitions
# of the same key in one file is a coin toss.
touch .env
sed -i -E '/^[[:space:]]*PA_TTRSS_DB_PASSWORD=/d' .env
{
    echo ""
    echo "# --- PanelAlpha tt-rss: read by docker compose, not by the app ---"
    cat "${DB_ENV}"
} >> .env
chmod 600 .env

# A copy the account owner can actually find. Same directory, same mode; this
# is what the README tells them to cat.
cat > "${STORE}/tt-rss-admin-credentials.txt" <<EOF
Tiny Tiny RSS - built-in administrator
  URL:      https://<your domain>/
  login:    admin
  password: $(sed -n 's/^TTRSS_ADMIN_PASS=//p' "${APP_ENV}")

Generated once per account by the PanelAlpha tt-rss recipe, replacing the
'password' that sql/pgsql/schema.sql seeds. Change it in Preferences and this
file stops being true.
EOF
chmod 600 "${STORE}/tt-rss-admin-credentials.txt"

# ---------------------------------------------------------------------------
# 3. Directories tt-rss writes into, and the two that must not be web-readable.
#
# tt-rss serves from its own repository root, so cache/ is inside the document
# root. It is not an incidental directory: cache/images holds the media
# tt-rss downloads out of articles, cache/upload holds what a user attaches,
# cache/export holds generated OPML and data exports, cache/feeds holds raw
# feed bodies. Upstream's own nginx marks that whole prefix `internal`
# (.docker/web-nginx/nginx.conf), which is nginx for "only reachable through an
# X-Accel-Redirect", and the repository ships no .htaccess at all -- there is
# nothing to carry that rule onto Apache. The generated vhost grants the
# document root (`Require all granted`, apache-vhost.stub) and denies only
# dotfiles, .git, docker-compose.y{,a}ml and panelalpha*, so without this file
# every cached article image and every export is a plain GET away. Measured on
# a deployed account: a file dropped at the repository root answered 200 with
# its contents, and the same file under cache/images/ and under lock/ answered
# 403 with these .htaccess in place.
#
# Nothing in the application links to these paths directly (grep for
# 'cache/images' across classes/, include/ and js/ finds no URL), so denying
# the prefix outright costs no functionality; the nginx_xaccel plugin that
# would need it is a docker-image-only local plugin and is not installed here.
#
# The vhost already denies dotfiles, .git, docker-compose.y{,a}ml and
# panelalpha* (apache-vhost.stub), which covers .env, .env.default and this
# recipe's own scripts. These two directories are what it does not know about.
mkdir -p cache/images cache/export cache/upload cache/feeds cache/feed-icons lock
for d in cache lock; do
    cat > "$d/.htaccess" <<'EOF'
# Installed by the PanelAlpha tt-rss recipe. tt-rss is served from its own
# repository root, so this directory is inside the document root; upstream's
# nginx marks the same prefix `internal` and the repository ships no .htaccess.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order Allow,Deny
    Deny from all
</IfModule>
EOF
done

# The two CLI entry points, which have no business answering a request.
# update.php and update_daemon2.php both refuse to run under a web SAPI -- they
# print "Please run this script using PHP CLI executable" and exit 1 -- but
# they do it *after* Apache has already echoed the `#!/usr/bin/env php` line
# that sits outside their `<?php`, and the refusal itself names the interpreter
# path. Measured: `GET /update.php` answered 200 with
# "PHP_EXECUTABLE is set to '/usr/local/bin/php'". Small, but it is a 200 that
# serves no purpose.
#
# A root .htaccess is otherwise avoided here: tt-rss needs no rewrite rules
# (every URL is a real file), and a file at the document root that Apache
# cannot parse takes the whole site down with it. This one is two directives.
cat > .htaccess <<'EOF'
# Installed by the PanelAlpha tt-rss recipe. These are command-line scripts;
# they refuse to run under a web SAPI, but not before Apache has served their
# shebang line and their error has named the PHP binary.
<FilesMatch "^(update|update_daemon2)\.php$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>
EOF
chmod 644 .htaccess

# 755, not 777: the container runs as this same account uid (the generated
# compose says `user: "<uid>:<gid>"`), so the web server is the owner.
# Config::sanity_check() fails the whole application when cache/images is not
# writable, and update.php --daemon needs lock/.
chmod 755 cache cache/images cache/export cache/upload cache/feeds cache/feed-icons lock

# No config.php. It is optional (include/functions.php: "config.php is
# optional"), everything it would hold is passed as TTRSS_* environment in the
# compose override, and a file in the document root that carries the database
# password is worth not creating -- Apache would execute it rather than serve
# it, but that is one misconfiguration away from being untrue.
echo "[tt-rss] prepare finished"
