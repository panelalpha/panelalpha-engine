#!/bin/bash
# Runs on the account after the clone and before the build.
#
# Everything here exists because a Servas checkout cannot say where its data
# goes. A redeploy clears and re-clones ~/project (engine#173), so the database,
# the application key and the account's credentials all have to live outside
# it. ~ is root-owned 0755 and nothing can be created there; ~/.panelalpha is
# created with the account and belongs to it, so the data directory is a child
# of that one. The compose override bind-mounts it at /data.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/servas"

say() { echo "[panelalpha] servas: $*"; }

mkdir -p "${DATA_HOME}"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# 1. The database
# ---------------------------------------------------------------------------
# Servas squashed its first thirteen migrations into database/schema/. The
# eight files left in database/migrations/ are the increments after that point
# -- none of them creates `users`, `links`, `tags` or `groups`, so the dump is
# not an optimisation here, it is where most of the schema is.
#
# `php artisan migrate` loads it itself, but only by shelling out:
# Illuminate\Database\Schema\SqliteSchemaState::load() runs
# `sqlite3 "<database>" < <dump>` (and the MySQL half runs the `mysql` client).
# The shared PHP base image has pdo_sqlite and sqlite3 as *extensions* and
# neither binary, so that call dies with `sh: 1: sqlite3: not found` and the
# deploy ends with an empty database -- which is the same 500 this recipe
# exists to fix, reached a different way.
#
# So the dump is loaded here instead, through PDO, before the container starts.
# It carries its own `INSERT INTO migrations` rows, so by the time the
# entrypoint runs `migrate`, MigrateCommand::prepareDatabase() sees
# hasRunAnyMigrations() true, skips loadSchemaState() entirely, and applies the
# eight increments normally. Only on a database that does not exist yet: a
# redeploy must never touch one that does.
DB_FILE="${DATA_HOME}/database.sqlite"
DUMP=database/schema/sqlite-schema.dump

if [ ! -f "${DB_FILE}" ]; then
    if [ ! -f "${DUMP}" ]; then
        say "no ${DUMP} in the checkout -- this is not a Servas tree" >&2
        exit 1
    fi
    umask 077
    PA_SERVAS_DB="${DB_FILE}" PA_SERVAS_DUMP="${DUMP}" php -r '
        $path = getenv("PA_SERVAS_DB");
        $dump = (string) file_get_contents(getenv("PA_SERVAS_DUMP"));
        $pdo = new PDO("sqlite:" . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($dump);
        $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = \"table\"")->fetchColumn();
        $migrations = (int) $pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        fwrite(STDERR, "[panelalpha] servas: created the database: {$tables} tables, {$migrations} migrations recorded\n");
    ' || { rm -f "${DB_FILE}"; say "could not load the stored schema" >&2; exit 1; }
fi
chmod 600 "${DB_FILE}"

# ---------------------------------------------------------------------------
# 2. APP_KEY
# ---------------------------------------------------------------------------
# Generated once and kept out of the checkout, for two separate reasons.
#
# The checkout does not survive a redeploy and the database does, so a key that
# lived in .env would be a different key on every deploy -- every session
# invalidated, and every value Laravel encrypted (Fortify's two_factor_secret
# and two_factor_recovery_codes are the ones Servas has) unreadable. The
# laravel manifest's `key:generate` is install-stage on purpose and cannot fix
# that on the second deploy.
#
# And it is not written into the .env below, because the engine copies whatever
# .env this hook leaves into .env.default at mode 644 -- readable by every
# other tenant on the host (engine#173). files/panelalpha/servas-install.php
# puts it into .env on the install and upgrade stages, after that copy has been
# taken.
if [ ! -f "${DATA_HOME}/app.key" ]; then
    (umask 077; php -r 'echo "base64:", base64_encode(random_bytes(32)), "\n";' > "${DATA_HOME}/app.key")
    say "generated an application key; it is in ~/.panelalpha/servas/app.key"
fi
chmod 600 "${DATA_HOME}/app.key"

# ---------------------------------------------------------------------------
# 3. The account
# ---------------------------------------------------------------------------
# Servas has no installer, no seeded user and no admin role. Its registration
# form is the only way anybody ever gets in, and config/fortify.php turns it on
# by default -- so a stock deploy on a public HTTPS address is a bookmark
# manager whose first visitor becomes its owner.
#
# The account is created at deploy time from these credentials instead, and the
# .env below closes registration. Written once and never rewritten: the install
# script is a no-op once a user exists, so a regenerated password would stop
# matching a database that survived the redeploy.
CREDENTIALS="${DATA_HOME}/credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    # No characters that need quoting in a shell, a URL or a form.
    password=$(LC_ALL=C tr -dc 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789' < /dev/urandom | head -c 20)
    if [ ${#password} -ne 20 ]; then
        say "could not generate a password; the install stage will leave the application without an account" >&2
        exit 1
    fi
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on the first deploy. This is the Servas account for
# this installation -- sign in at https://<your-domain>/login .
#
# Servas's registration form is the only way an account is ever created, and it
# is open by default, so on a public address the first visitor would have been
# the owner. This account was created at deploy time instead and registration
# was turned off; set SERVAS_ENABLE_REGISTRATION=true in ~/project/.env to open
# it again. Change the password under Profile and this file stops being
# interesting.
SERVAS_EMAIL=admin@localhost
SERVAS_PASSWORD=${password}
EOF
    chmod 600 "${CREDENTIALS}"
    say "generated the account password; it is in ~/.panelalpha/servas/credentials"
fi
chmod 600 "${CREDENTIALS}"

# ---------------------------------------------------------------------------
# 4. Writable directories
# ---------------------------------------------------------------------------
# The checkout ships these as .gitignore stubs. framework/sessions missing is a
# 500 on the first request rather than a warning.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/app/public storage/logs bootstrap/cache

# ---------------------------------------------------------------------------
# 5. .env
# ---------------------------------------------------------------------------
# A real file rather than the compose `environment:` block, because the
# application is served by mod_php: Apache does not publish the container
# environment as $_SERVER, and Laravel's env() reads $_SERVER and $_ENV. A
# value set only in the compose file reaches `php artisan` on the install stage
# and never reaches a web request, which for DB_DATABASE would mean the
# migration and the site using two different databases.
#
# Written from scratch rather than derived from .env.example, which ships
# APP_ENV=local, APP_DEBUG=true and a MySQL block. Left as they are, the engine
# copies them (they are not secrets, so nothing replaces them), the application
# runs in debug mode on a public address, and EnvSidecars reads DB_CONNECTION
# and adds a MariaDB container nobody asked for.
#
# Written on every deploy, because these values are the deploy's rather than
# the operator's -- the operator's own overrides come from the panel's
# env_vars, which the engine merges over this file.
#
# No APP_KEY: see 2. APP_URL is a placeholder for the same reason -- the
# account's public address is in the container environment at install time, and
# panelalpha/servas-install.php writes both.
#
# Left at the mode the engine writes its own .env with, and not tightened to
# 0600, which is a real trap here: EnvSidecars::variableMap() reads .env with
# plain file_get_contents() as www-data rather than through the account's file
# layer, so a 0600 .env is invisible to it and it falls back to .env.example --
# which says DB_CONNECTION=mysql. Measured: a MariaDB container appeared beside
# the app and its DB_* landed in the compose `environment:` block, where they
# beat env_file for `php artisan` while the web request went on reading .env.
# One database for the migration and another for the site. There is nothing
# secret in this file; the application key is added to it from inside the
# container, by panelalpha/servas-install.php, which chmods it 0600 then.
cat > .env <<'EOF'
# Written by PanelAlpha. The application key and this installation's account
# credentials are in ~/.panelalpha/servas/ .
APP_NAME=Servas
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost
LOG_CHANNEL=stderr
LOG_LEVEL=error

# Servas's registration form is the only way an account is created and it has
# no admin role, so an open one on a public address hands the installation to
# whoever finds it. The account was created at deploy time instead; set this to
# true to let other people sign up.
SERVAS_ENABLE_REGISTRATION=false
SERVAS_SHOW_APP_VERSION=true

# ~/.panelalpha/servas, bind-mounted at /data by the compose override. Outside
# the checkout on purpose: a redeploy re-clones ~/project and would otherwise
# take every bookmark with it (engine#173). Servas reads DB_DATABASE straight
# into the connection -- config/database.php does not wrap it in
# database_path() -- so an absolute path is used as written (engine#167 does
# not apply here).
DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite
DB_FOREIGN_KEYS=true

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=database
SESSION_LIFETIME=120
EOF
chmod 644 .env

say "prepared; database at ${DB_FILE}"
