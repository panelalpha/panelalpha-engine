#!/bin/sh
# Runs inside the account's own container, in the install and upgrade stages,
# before Apache is started. Everything here needs either the database
# credentials (which do not exist when hooks/prepare.sh runs) or Composer's
# plugins (which the host build is not allowed to run).
#
# Idempotent throughout: the upgrade stage replays it on every redeploy, and
# the seeding half is guarded on an empty database rather than on the stage, so
# a retried first deploy cannot overwrite a site that already has content.
set -e

PROJECT_DIR="$(pwd)"
CONSOLE="${PROJECT_DIR}/vendor/bin/contao-console"

# Composer wants a home it can write; the account uid has no $HOME inside this
# container. Keeping it in var/ means the cache is thrown away with the
# checkout, which is correct -- vendor/ is resolved on the host, and what runs
# here is an install from the lock.
export COMPOSER_HOME="${PROJECT_DIR}/var/composer"
export COMPOSER_MEMORY_LIMIT=-1
mkdir -p "${COMPOSER_HOME}"

# The credentials hooks/prepare.sh generated. Exported rather than merely
# sourced: the PHP one-liners below read them with getenv().
CONTAO_ADMIN_USERNAME=admin
CONTAO_ADMIN_EMAIL=admin@example.com
CONTAO_ADMIN_PASSWORD=
if [ -f .panelalpha-admin-password ]; then
    # shellcheck disable=SC1091
    . ./.panelalpha-admin-password
fi
export CONTAO_ADMIN_USERNAME CONTAO_ADMIN_EMAIL CONTAO_ADMIN_PASSWORD

# ---------------------------------------------------------------------------
# 1. DATABASE_URL
# ---------------------------------------------------------------------------
#
# `database: mysql` in panelalpha.yaml gets a database and a user on the
# account's own MySQL server -- visible in the panel, openable in phpMyAdmin,
# inside the account's backup -- and the engine passes the five parts as
# environment variables. Contao wants one DSN, so it is assembled here.
#
# Written to .env.local, which is the file Symfony documents for per-install
# values and the one Contao's own installer writes. It must *not* go into .env:
# that file is mounted as real environment variables by the compose file, and a
# stale copy of it would then outrank whatever this stage wrote.
if [ -n "${DB_DATABASE:-}" ]; then
    DB_PORT="${DB_PORT:-3306}"
    # rawurlencode, because a generated password lands inside a URL: a `/`, `@`
    # or `#` in it would silently move the host or truncate the DSN.
    DB_USER_ENC=$(php -r 'echo rawurlencode(getenv("DB_USERNAME") ?: "");')
    DB_PASS_ENC=$(php -r 'echo rawurlencode(getenv("DB_PASSWORD") ?: "");')

    TMP_ENV="${PROJECT_DIR}/.env.local.tmp"
    : > "${TMP_ENV}"
    chmod 600 "${TMP_ENV}"
    if [ -f "${PROJECT_DIR}/.env.local" ]; then
        grep -v '^DATABASE_URL=' "${PROJECT_DIR}/.env.local" >> "${TMP_ENV}" || true
    fi
    printf 'DATABASE_URL="mysql://%s:%s@%s:%s/%s?charset=utf8mb4"\n' \
        "${DB_USER_ENC}" "${DB_PASS_ENC}" "${DB_HOST}" "${DB_PORT}" "${DB_DATABASE}" >> "${TMP_ENV}"
    mv "${TMP_ENV}" "${PROJECT_DIR}/.env.local"
    chmod 600 "${PROJECT_DIR}/.env.local"
    echo "[contao] DATABASE_URL written to .env.local"
else
    echo "[contao] no DB_DATABASE in the environment -- the install will fail" >&2
fi

# ---------------------------------------------------------------------------
# 2. The install Composer was not allowed to do on the host
# ---------------------------------------------------------------------------
#
# The php manifest installs with `--no-scripts --no-plugins` because both run
# arbitrary PHP out of a customer repository on the *host* daemon
# (PhpHostBuild::SAFE_INSTALL_FLAGS), and PhpHostBuild::mayRunPlugins() lifts
# that only for an allowlist of scaffolding plugins that Contao's are not on.
# For Contao that host pass produces a directory of packages and not an
# application:
#
#   * contao/manager-plugin subscribes to POST_INSTALL_CMD and writes
#     vendor/contao/manager-plugin/.generated/plugins.php. PluginLoader
#     include()s exactly that file and nothing else, so without it Contao's
#     kernel registers zero bundles -- no routes, no DCA, no back end.
#     `composer dump-autoload` does not help: the listener is on
#     POST_INSTALL_CMD, not POST_AUTOLOAD_DUMP.
#   * contao-components/installer is a custom installer for the
#     `contao-component` package type; its getInstallPath() is
#     extra.contao-component-dir . '/' . basename($package). With it off, the
#     nineteen contao-components packages land in vendor/ and assets/ is never
#     created, so every back-end script and stylesheet is a 404.
#
# Measured on this host: `--no-dev --no-scripts --no-plugins` over this
# manifest installs 180 packages in 25 s and leaves no public/, no assets/ and
# no .generated/plugins.php.
#
# Running it again here, with plugins and scripts on, is cheap: the lock the
# host pass wrote is authoritative, so nothing is re-resolved. Composer asks
# the components installer where each contao-component belongs, finds assets/
# empty and fetches those nineteen into it; then the plugin writes
# plugins.php; then the root package's post-install-cmd runs
# vendor/bin/contao-setup, which installs the skeleton (public/index.php,
# public/preview.php, public/.htaccess, bin/console), the bundle assets, the
# symlinks, and warms the prod cache.
echo "[contao] running composer install with plugins enabled"
composer install --no-dev --no-interaction --optimize-autoloader --no-progress

# The copies the host pass left in vendor/ are dead weight once the real ones
# are in assets/ -- around 30 MB of a 2000 MB account, and a second copy of
# TinyMCE and MooTools inside the project is a confusing thing to find.
if [ -d vendor/contao-components ]; then
    for comp in vendor/contao-components/*; do
        name=$(basename "${comp}")
        # Not `installer`: that one is the Composer plugin itself and belongs
        # in vendor/.
        [ "${name}" = "installer" ] && continue
        [ -d "assets/${name}" ] && rm -rf "${comp}"
    done
fi

# ---------------------------------------------------------------------------
# 3. Schema, content and credentials
# ---------------------------------------------------------------------------

# Is this a database that has never held a Contao site? Asked of the database
# and not of the deploy stage, so a first deploy that failed halfway and was
# retried does not restore the demo over content someone has already written.
FRESH=$(php -r '
$dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE"));
try {
    $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $n = (int) $pdo->query("SELECT COUNT(*) FROM tl_page")->fetchColumn();
    echo $n === 0 ? "yes" : "no";
} catch (Throwable $e) {
    echo "yes";
}
')

if [ "${FRESH}" = "yes" ]; then
    BACKUP=$(ls -1 var/backups/backup__*.sql 2>/dev/null | head -1)
    if [ -n "${BACKUP}" ]; then
        echo "[contao] empty database: restoring the official demo website"
        # The dump ships with contao/contao-demo and carries its own CREATE
        # TABLEs, so it is restored before the migration rather than after: the
        # migration then reconciles the demo's schema with the installed
        # release's, which is what upstream's own
        # `composer create-project contao/contao-demo` does.
        php "${CONSOLE}" contao:backup:restore "$(basename "${BACKUP}")" --no-interaction
    else
        echo "[contao] no demo backup found; starting from an empty site"
    fi
fi

echo "[contao] running contao:migrate"
# --no-backup: contao:migrate dumps the whole database into var/backups before
# it runs, and var/ is inside the checkout, which the next deploy clears -- so
# the dump costs time and disk and is gone before anyone could use it. The
# account's own backup covers the database.
php "${CONSOLE}" contao:migrate --no-interaction --with-deletes --no-backup

# The demo dump's file references are UUIDs pointing at files/contaodemo/*,
# which the tarball put on disk in the prepare hook. filesync reconciles the
# two; without it a freshly restored demo can show broken images.
php "${CONSOLE}" contao:filesync || true

# --- credentials -----------------------------------------------------------
#
# THE DEMO DUMP SHIPS A WORKING ADMINISTRATOR. tl_user row 1 is `k.jones`
# (Kevin Jones), admin=1, login=1, with the bcrypt hash of `kevinjones` --
# verified against this dump with password_verify(). It is a published default
# credential on a back end that is one URL away from the public internet, and
# tl_member ships `j.smith` the same way. So every account the dump created is
# given a random password and disabled here, before anything is served, and the
# account's own administrator is created separately below.
#
# Disabled rather than deleted: the demo's pages, articles and news items carry
# these ids in their author and permission columns, and deleting the rows would
# leave the example site referencing users that do not exist.
if [ "${FRESH}" = "yes" ]; then
    php -r '
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE"));
    $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $keep = getenv("CONTAO_ADMIN_USERNAME") ?: "admin";
    foreach (["tl_user", "tl_member"] as $table) {
        try {
            $rows = $pdo->query("SELECT id FROM $table")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            continue;
        }
        $upd = $pdo->prepare("UPDATE $table SET password = ?, disable = 1 WHERE id = ? AND username <> ?");
        foreach ($rows as $id) {
            $upd->execute([password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $id, $keep]);
        }
        printf("[contao] %s: %d seeded account(s) disabled and re-passworded\n", $table, count($rows));
    }
    '
fi

# The account's administrator. Created only when there is not one already, so a
# redeploy never resets a password the customer has since changed.
if [ -n "${CONTAO_ADMIN_PASSWORD}" ]; then
    HAS_ADMIN=$(php -r '
    $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE"));
    try {
        $pdo = new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare("SELECT COUNT(*) FROM tl_user WHERE username = ?");
        $st->execute([getenv("CONTAO_ADMIN_USERNAME") ?: "admin"]);
        echo ((int) $st->fetchColumn()) > 0 ? "yes" : "no";
    } catch (Throwable $e) {
        echo "yes";
    }
    ')

    if [ "${HAS_ADMIN}" = "no" ]; then
        echo "[contao] creating the administrator ${CONTAO_ADMIN_USERNAME}"
        php "${CONSOLE}" contao:user:create \
            --username="${CONTAO_ADMIN_USERNAME}" \
            --name="Administrator" \
            --email="${CONTAO_ADMIN_EMAIL}" \
            --password="${CONTAO_ADMIN_PASSWORD}" \
            --language=en \
            --admin \
            --no-interaction
    else
        echo "[contao] administrator ${CONTAO_ADMIN_USERNAME} already exists, leaving it alone"
    fi
fi

# contao-setup ran as part of the Composer install above, before the schema
# existed. Warming again now that it does keeps the first real request off the
# slow path -- and a cold prod cache is what turns the first seconds after
# `up -d` into 500s (engine#90; the compose override's healthcheck is the other
# half of that).
php "${CONSOLE}" cache:clear --no-warmup --env=prod
php "${CONSOLE}" cache:warmup --env=prod

echo "[contao] setup finished"
