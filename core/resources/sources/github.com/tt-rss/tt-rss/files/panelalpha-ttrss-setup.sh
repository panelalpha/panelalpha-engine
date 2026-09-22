#!/bin/sh
# Runs inside the app container, from /app, on the install and upgrade stages
# -- before Apache binds, because the staged entrypoint execs the serve command
# last.
#
# Named panelalpha-* on purpose: apache-vhost.stub denies
# `^(?:docker-compose\.ya?ml|panelalpha[-.])` for the whole document root, and
# tt-rss's document root is this directory.
set -e

say() { printf '[tt-rss] %s\n' "$1" >&2; }

# ---------------------------------------------------------------------------
# 1. Wait for the database.
#
# `depends_on: {condition: service_healthy}` already gates the container on
# pg_isready, so this should return on the first attempt. It stays because
# pg_isready answers as soon as the postmaster accepts connections, which on a
# first boot is a moment before initdb has finished creating the role and the
# database the entrypoint was asked for -- and the failure that produces is
# `password authentication failed`, thirty seconds into a deploy, with no
# retry anywhere else in the chain.
#
# PDO rather than pg_isready or psql: the shared PHP base image has pdo_pgsql
# and no postgresql-client at all.
say "waiting for ${TTRSS_DB_HOST}:${TTRSS_DB_PORT}"
php -r '
$dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s",
    getenv("TTRSS_DB_HOST"), getenv("TTRSS_DB_PORT"), getenv("TTRSS_DB_NAME"));
for ($i = 1; $i <= 90; $i++) {
    try {
        new PDO($dsn, getenv("TTRSS_DB_USER"), getenv("TTRSS_DB_PASS"),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        exit(0);
    } catch (Throwable $e) {
        if ($i === 90) {
            fwrite(STDERR, "[tt-rss] database never became reachable: " . $e->getMessage() . "\n");
            exit(1);
        }
        sleep(2);
    }
}'
say "database reachable"

# pg_trgm, the way upstream's own startup.sh does it (`psql -c "create
# extension if not exists pg_trgm"`). Nothing in the schema requires it, so
# this is best-effort: it is here because a tt-rss installed by hand on
# postgres has it, and because the sidecar's user is the database owner, which
# is the only moment it can be created.
php -r '
try {
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s",
        getenv("TTRSS_DB_HOST"), getenv("TTRSS_DB_PORT"), getenv("TTRSS_DB_NAME"));
    $pdo = new PDO($dsn, getenv("TTRSS_DB_USER"), getenv("TTRSS_DB_PASS"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("create extension if not exists pg_trgm");
} catch (Throwable $e) {
    fwrite(STDERR, "[tt-rss] pg_trgm not created (optional): " . $e->getMessage() . "\n");
}' || true

# ---------------------------------------------------------------------------
# 2. The schema, and every migration since.
#
# This is the whole installer. tt-rss has no /install wizard any more -- there
# is no install/ directory in the tree and no route that offers one -- and
# `update.php --update-schema` is what upstream's own container runs on every
# start. On an empty database it applies sql/pgsql/schema.sql; on a populated
# one it replays sql/pgsql/migrations/<n>.sql from ttrss_version up to
# Config::SCHEMA_VERSION and does nothing when they match. That last property
# is why this runs on the upgrade stage too: a redeploy that brings newer code
# to an older database is exactly the case it handles, and a redeploy that
# brings nothing new costs one SELECT.
#
# force-yes because there is no terminal to answer the prompt.
say "applying schema / migrations"
php update.php --update-schema=force-yes

# ---------------------------------------------------------------------------
# 3. The built-in administrator.
#
# sql/pgsql/schema.sql seeds `admin` with the SHA1 of 'password' and tt-rss
# puts a red banner on every page until it changes. A deploy that stops at
# "the login form renders" has published a public URL whose administrator
# credentials are in the repository.
#
# --user-check-password first, so this is not a password reset on every
# redeploy: once the owner has changed it in Preferences, the seeded value no
# longer matches and this leaves their password alone. Belt and braces for the
# first deploy, where the check does match and the generated password is set.
#
# The password reaches this script as an environment variable from
# ~/.panelalpha/tt-rss-app.env (mode 600, outside the document root) and is
# passed to update.php on its argv, which is the only interface it offers --
# the same thing upstream's startup.sh does. Visible in `ps` to this account
# inside its own container, and nowhere else.
if [ -z "${TTRSS_ADMIN_PASS}" ]; then
    say "TTRSS_ADMIN_PASS is empty -- refusing to leave admin on the seeded password"
    exit 1
fi

if php update.php --user-check-password "admin:password" >/dev/null 2>&1; then
    php update.php --user-set-password "admin:${TTRSS_ADMIN_PASS}" >/dev/null
    say "built-in admin password rotated (see ~/.panelalpha/tt-rss-admin-credentials.txt)"
else
    say "built-in admin password is not the seeded default; left untouched"
fi

# Access level 10 is what the schema seeds and what the admin needs to reach
# Preferences -> System. Restated rather than assumed, because
# ADMIN_USER_ACCESS_LEVEL exists upstream precisely because people change it.
php update.php --user-set-access-level "admin:10" >/dev/null 2>&1 || true

say "setup complete"
