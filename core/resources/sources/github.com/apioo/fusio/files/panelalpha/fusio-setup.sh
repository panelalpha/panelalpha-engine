#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy.
#
# It lives under panelalpha/ rather than at the top of the checkout because
# Fusio's document root is public/ -- nothing at this level is web content.
set -e
cd /app

log() { echo "[fusio] $*"; }

# ---------------------------------------------------------------------------
# 1. APP_CONNECTION, from the database the engine provisioned.
#
# `database: mysql` in panelalpha.yaml is what asks for it: AppDatabase creates
# a database and user on the account's own MySQL server -- visible in the
# panel, openable in phpMyAdmin, included in the account's backup -- and the
# generated compose file passes the credentials as DB_*. This is the earliest
# point at which they exist; hooks/prepare.sh runs before the database does,
# which is exactly why it deleted the committed APP_CONNECTION instead of
# correcting it.
#
# Fusio wants one DSN rather than five fields. `pdo-mysql` is Doctrine DBAL's
# driver name, not PDO's, and it is what fusio/impl's own parser expects.
if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    echo "[fusio] no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# The credentials go into a URL, so they are percent-encoded rather than
# assumed safe: AppDatabase generates the password and nothing promises it
# holds no `@`, `:` or `/`, each of which would move the parser's idea of
# where the host starts. PHP is in the container and has the right rules.
urlencode() {
    php -r 'echo rawurlencode($argv[1]);' "$1"
}

DSN="pdo-mysql://$(urlencode "${DB_USERNAME}"):$(urlencode "${DB_PASSWORD}")@${DB_HOST}:${DB_PORT:-3306}/${DB_DATABASE}"

# Written whenever it does not match, not only when it is missing. The
# credentials are stable across redeploys (AppDatabase keeps the password in
# the account's encrypted details precisely so a redeploy cannot invalidate a
# config file), but a restored backup, a hand-edited file or a re-clone that
# brought the committed value back is a 500 on every request, and the values
# the container was handed are by definition the ones that work.
if ! grep -qxF "APP_CONNECTION=\"${DSN}\"" .env 2>/dev/null; then
    sed -i '/^APP_CONNECTION=/d' .env
    printf 'APP_CONNECTION="%s"\n' "$DSN" >> .env
    log "wrote APP_CONNECTION for ${DB_DATABASE}@${DB_HOST}"
fi

# The public URL, so Fusio stops guessing it from the Host header.
#
# It matters more than it looks: marketplace:install below bakes
# frameworkConfig->getDispatchUrl() into the backend app's index.html at
# install time, and a CLI run has no Host header to guess from -- without
# this the administration app would ship pointing at http://localhost/.
# APP_URL is already in the container environment (the engine sets it from the
# account's main domain); writing the same value into .env is what makes the
# web requests agree with the CLI, since env_file and the runtime .env are two
# different readers of the same names.
if [ -n "${APP_URL:-}" ] && ! grep -qxF "APP_URL=\"${APP_URL}\"" .env 2>/dev/null; then
    sed -i '/^APP_URL=/d' .env
    printf 'APP_URL="%s"\n' "$APP_URL" >> .env
    log "wrote APP_URL=${APP_URL}"
fi

# APP_CONNECTION is not in the container environment at all -- prepare.sh took
# the committed one out so that nothing would shadow this file -- but APP_URL
# is, and an export here costs nothing and makes the commands below read
# exactly what a web request will.
APP_CONNECTION="$DSN"
export APP_CONNECTION

# ---------------------------------------------------------------------------
# 2. The compiled service container.
#
# ContainerBuilder::build() writes cache/container.php and, with APP_DEBUG
# false, ConfigCache::isFresh() is satisfied by the file merely existing: no
# timestamps are checked. That is right for a running site and wrong for the
# moment after a deploy, when configuration.php, provider.php or vendor/ may
# all have changed. Dropping it costs one recompile.
rm -f cache/container.php cache/container.php.meta

# ---------------------------------------------------------------------------
# 3. The schema.
#
# Doctrine migrations, over the paths psx/framework registers -- including
# Fusio\Impl\Migrations, which carries the whole installation: tables, the
# categories, the scopes, the roles and every default operation. There is no
# separate installer to run and no /install wizard to leave open, which is
# the difference between this application and most PHP ones.
#
# --no-interaction answers the "you are about to execute a migration"
# confirmation; --allow-no-migration makes an already-current database a
# success rather than an error, which is what the upgrade stage needs.
# 512M because compiling the container and running the migrations in one
# process is more than the stock CLI limit on a first install.
log "running migrations"
php -d memory_limit=512M bin/fusio migrations:migrate --no-interaction --allow-no-migration

# ---------------------------------------------------------------------------
# 4. The first administrator.
#
# Fusio's own installation seeds a single internal `Administrator` row with a
# password generated by TokenGenerator and told to nobody, which is why its
# own readiness check (`system:check user`) asks for *more than one* user
# rather than one. That check is the guard here: it is exactly the question
# "has a human account been created yet", and it keeps the upgrade stage from
# trying to add the same account again.
#
# The password was generated per account by hooks/prepare.sh, before anything
# could serve a request. `system:user_add` takes every field as an option, so
# nothing here is interactive.
if php bin/fusio system:check user >/dev/null 2>&1; then
    log "administrator already exists; not creating one"
else
    ADMIN_USER="${FUSIO_ADMIN_USER:-admin}"
    ADMIN_EMAIL="${FUSIO_ADMIN_EMAIL:-admin@example.com}"
    ADMIN_PASSWORD=$(cat .panelalpha-admin-password)

    php bin/fusio system:user_add \
        --role 1 \
        --username "$ADMIN_USER" \
        --email "$ADMIN_EMAIL" \
        --password "$ADMIN_PASSWORD" >/dev/null
    log "created administrator ${ADMIN_USER} <${ADMIN_EMAIL}>"
fi

# ---------------------------------------------------------------------------
# 5. The administration app.
#
# Fusio ships an API and no user interface: the backend at /apps/fusio is a
# separate bundle downloaded from api.fusio-project.org, and a deploy that
# skips it leaves an account with nothing to log in to. The command also
# creates the OAuth2 app row the interface authenticates as, and rewrites the
# API URL into its index.html -- which is what section 1 set APP_URL for.
#
# Not fatal, on purpose, and it cannot be: marketplace:install catches every
# throwable itself and still exits 0. This needs outbound HTTPS from the
# account's container, so it is the one step here that depends on something
# outside the host. A failure leaves a working API whose interface can be
# installed later with the same command, and says so.
if [ -f public/apps/fusio/index.html ]; then
    log "backend app already installed"
else
    php bin/fusio marketplace:install fusio || true
    if [ -f public/apps/fusio/index.html ]; then
        log "installed the backend app at /apps/fusio"
    else
        log "WARNING: could not install the backend app from the marketplace."
        log "WARNING: the API is up; retry with: php bin/fusio marketplace:install fusio"
    fi
fi

# ---------------------------------------------------------------------------
# 6. The routing cache.
#
# Routes live in the database (RoutingParser\DatabaseParser behind a
# CachedParser), so the migrations and the app install above both changed
# them under a cache written by an earlier deploy.
php bin/fusio system:clear_cache >/dev/null 2>&1 || true

log "setup finished"
