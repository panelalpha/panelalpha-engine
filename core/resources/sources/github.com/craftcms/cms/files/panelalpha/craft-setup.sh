#!/bin/sh
# Runs inside the container, in the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy, and a crash-looping container would replay it on every boot.
set -e
cd /app

say() { echo "[craft] $*" >&2; }

# Craft's own console asks for more than the stock CLI memory_limit in two
# places: the install migration, which creates ~60 tables and the whole default
# project config, and `up`, which replays migrations and applies project config
# changes. Well below the service's cgroup ceiling.
CRAFT="php -d memory_limit=512M craft"

# ---------------------------------------------------------------------------
# 1. Wait for the database.
#
# The install migration is the first thing that touches it, and a MySQL that is
# still starting turns into `install`'s DbConnectException branch -- which runs
# `setup/welcome`, an interactive wizard, in a stage that has no terminal.
i=0
while [ "$i" -lt 60 ]; do
    if php -r '
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s",
            getenv("DB_CONNECTION") === "pgsql" ? "pgsql" : "mysql",
            getenv("DB_HOST") ?: "127.0.0.1",
            getenv("DB_PORT") ?: "3306",
            getenv("DB_DATABASE"));
        try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }
        exit(0);
    ' 2>/dev/null; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
[ "$i" -lt 60 ] || say "WARNING: database still unreachable after 120s; continuing anyway"

# ---------------------------------------------------------------------------
# 2. The install, run here so that it is never run by a visitor.
#
# This is the security half of the recipe. `php craft install` is Craft's
# first-run setup and it has a web face: an uninstalled Craft answers
# /index.php?p=admin/install with the installer, and whoever reaches it first
# becomes the administrator of the site. Running it from the install stage,
# before Apache binds, with a password generated per account into
# ~/.panelalpha/craft.env, means the window never opens.
#
# `install/check` exits 1 when Craft is not installed, which is what makes this
# idempotent: the upgrade stage of a redeploy finds an installed Craft and
# skips straight to `up`.
if ! $CRAFT install/check >/dev/null 2>&1; then
    # `$APP_URL`, not the URL itself. Craft stores a site's base URL verbatim
    # and resolves it through App::parseEnv() on every read, so a `$NAME` is
    # looked up in the environment each time -- which means the site follows
    # the account's domain when it changes, with no reinstall and no row to
    # edit. InstallController also skips writing PRIMARY_SITE_URL into .env
    # when the value it was given already starts with `$`.
    site_url='$APP_URL'
    if [ -z "${APP_URL:-}" ]; then
        # No domain on the account yet. Craft validates the *parsed* value, so
        # an unset $APP_URL would fail validation and fail the deploy.
        site_url='http://localhost'
        say "WARNING: no APP_URL; installing with a placeholder site URL"
    fi

    say "installing Craft"
    $CRAFT install \
        --interactive=0 \
        --username="${PA_CRAFT_ADMIN_USERNAME:-admin}" \
        --email="${PA_CRAFT_ADMIN_EMAIL:-admin@example.com}" \
        --password="${PA_CRAFT_ADMIN_PASSWORD:?no administrator password in the environment}" \
        --siteName="${PA_CRAFT_SITE_NAME:-Craft CMS}" \
        --siteUrl="$site_url" \
        --language=en-US
fi

# ---------------------------------------------------------------------------
# 3. Migrations and project config, on every deploy that changed the code.
#
# `up` is Craft's own "make the database match the code": pending Craft and
# plugin migrations, then any project config changes in config/project/.
# `--no-backup` because the backup it would otherwise take shells out to
# `mysqldump`, and the shared PHP base image has no MySQL client in it -- there
# is no mysql-client line in resources/deploy/templates/dockerfile/php-base.stub.
# A failed backup under --interactive=0 aborts the command.
$CRAFT up --interactive=0 --no-backup

# ---------------------------------------------------------------------------
# 4. Clear what the last deploy compiled.
#
# storage/ is a bind mount that outlives the checkout, so the compiled
# templates, the data caches and the control panel's published resources are
# all from the *previous* code -- and web/cpresources is keyed on a hash of a
# source directory that a re-clone has just given new mtimes.
# Optional: a failure here is a cold cache, not a broken site.
$CRAFT clear-caches/all --interactive=0 >/dev/null 2>&1 || true
