#!/bin/bash
set -e
cd ~/project

# github.com/SergiX44/XBackBone is a monorepo, and neither half of it is a
# deployable application on its own:
#
#   app/    the skeleton that *is* deployed -- public/index.php, bootstrap/,
#           storage/, the `xbb` console, and a composer.json whose whole
#           content is `require: xbackbone/core`.
#   core/   the Laravel package that holds the application: XBB\, the routes,
#           the views, the migrations, the installer, and the compiled
#           public/build bundle.
#
# The repository root has no index.php and no composer.json, which is exactly
# the `serving-missing_entry` verdict this hook exists to fix -- detection was
# right about the tree. Upstream's install is `composer create-project
# xbackbone/app`, which downloads the split of `app/` and resolves the split of
# `core/` from Packagist as `dev-master`.
#
# This hook does the same thing without leaving the checkout: `app/` becomes
# the project root and `core/` is wired in as a Composer *path* repository, so
# the core that serves requests is the one the account cloned. That matters
# more than tidiness here. `app/composer.json` has no lock file (`.gitignore`
# excludes it) and asks for `dev-master`, so resolving from Packagist would
# pull whatever the split repository's master happened to be at deploy time --
# a different commit from the monorepo the account asked for, and a different
# one again on the next redeploy.
#
# Two things are patched into that composer.json and nothing else is touched:
#   * `require.php: ^8.4`. core/composer.json declares it, app/composer.json
#     declares no PHP at all, and PhpRuntime reads only the root manifest --
#     so without this the account gets the engine default (8.3) and Composer
#     refuses the resolve. This hook runs before detection, so the patched
#     file is what picks the image.
#   * the path repository for core/, with an explicit `dev-master` version so
#     resolution does not depend on the shape of the engine's clone.
#
# Everything generated per account -- the application key, the administrator
# password, the database and the uploads -- lives in ~/.panelalpha/xbackbone,
# which the compose override bind-mounts at /data. A redeploy re-clones
# ~/project and would otherwise take all four with it (engine#173).

DATA_HOME="${HOME}/.panelalpha/xbackbone"

# ~ itself is root-owned 0755, so a new directory cannot be created there;
# ~/.panelalpha is created with the account and belongs to it.
mkdir -p "${DATA_HOME}/uploads"
chmod 700 "${DATA_HOME}"

# --------------------------------------------------------- 1. the application

if [ -f app/composer.json ] && [ -f core/composer.json ]; then
    echo "[xbackbone] promoting the app/ skeleton to the project root"
    shopt -s dotglob nullglob
    for entry in app/*; do
        name="${entry#app/}"
        case "${name}" in
            # Nothing in app/ is called any of these, and the checkout's own
            # git directory and this hook are not something to move on a
            # filename match that should never happen.
            .|..|.git|core|panelalpha|panelalpha-after-clone.sh) continue ;;
        esac
        rm -rf "./${name}"
        mv "${entry}" ./
    done
    shopt -u dotglob nullglob
    rmdir app
fi

if [ ! -f public/index.php ] || [ ! -f core/composer.json ]; then
    echo "[xbackbone] no public/index.php or core/composer.json after restructuring -- this is not an XBackBone checkout" >&2
    exit 1
fi

# Composer, not sed: composer.json is JSON and the account has PHP (it is a PHP
# hosting account) but no jq. Read and written with the same encoder so the
# file stays valid whatever upstream adds to it.
php -r '
$path = "composer.json";
$data = json_decode(file_get_contents($path), true);
if (!is_array($data)) { fwrite(STDERR, "[xbackbone] composer.json is not readable JSON\n"); exit(1); }
$data["require"] = ["php" => "^8.4"] + ($data["require"] ?? []);
$data["repositories"] = [[
    "type" => "path",
    "url" => "core",
    "options" => ["symlink" => false, "versions" => ["xbackbone/core" => "dev-master"]],
]];
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

# ------------------------------------------------------- 2. persistent state

# The local disk's root is storage_path("app") and config/filesystems.php
# hard-codes it -- there is no environment variable for it, and the config
# directory belongs to the core package rather than to the skeleton, so there
# is nothing here to override. A symlink is the lever. It dangles on the
# account (nothing is at /data outside the container) and resolves inside it.
rm -rf storage/app
ln -sfn /data/uploads storage/app

# storage/ arrives from the skeleton with these, but only as .gitignore stubs,
# and framework/sessions missing is a 500 on the first request rather than a
# warning.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# --------------------------------------------------- 3. per-account secrets

# APP_KEY, generated once and kept out of the checkout so a redeploy does not
# invalidate every session and every encrypted value the previous deploy wrote
# into a database that survives it.
#
# The .env.example this repository ships carries a *committed* application key
# (`base64:88Nwiwz8SgR2v7Spx27RDdj7uCYidIwKCmzQCs4l0V4=`) -- the same one in
# every clone of this repository there is, which would make every deployment's
# cookies and encrypted columns forgeable by anyone who read the file. It is
# never copied: the .env below is written from scratch, and the key is put into
# it by panelalpha/xbb-install.php on the install stage rather than here,
# because the engine copies this hook's .env into a world-readable .env.default
# (engine#173).
if [ ! -f "${DATA_HOME}/app.key" ]; then
    (umask 077; printf 'base64:%s\n' "$(openssl rand -base64 32)" > "${DATA_HOME}/app.key")
fi

# The administrator. XBackBone's installer is first-visitor-wins: until it has
# been completed, EnsureInstalled redirects every request to /install and
# whoever opens it first picks the database, the storage backend and the admin
# account. panelalpha/xbb-install.php closes that window on the install stage,
# from these credentials, before Apache binds.
#
# Written once per account and never rewritten: the install script is a no-op
# once XBackBone reports itself installed, so a regenerated password would
# stop matching the account in a database that survived the redeploy.
CREDENTIALS="${DATA_HOME}/admin-credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on first deploy. This is the XBackBone administrator
# for this account -- sign in at https://<your-domain>/login .
#
# XBackBone's web installer creates the first administrator, and on a public
# address that is whoever loads /install first. It was run at deploy time
# instead, with these values, and is now closed. Change the password under
# Profile and this file stops being interesting.
XBACKBONE_ADMIN_EMAIL=admin@localhost
XBACKBONE_ADMIN_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
EOF
    chmod 600 "${CREDENTIALS}"
fi

# ------------------------------------------------------------------ 4. .env

# mod_php does not publish the container environment as $_SERVER, and Laravel's
# env() reads $_SERVER and $_ENV -- so a value that only exists in the compose
# file reaches `php xbb` on the install stage and never reaches a web request.
# The application needs a real .env file.
#
# APP_URL is a placeholder: the account's public address is in the container
# environment at install time, and panelalpha/xbb-install.php hands it to
# XBackBone's own installer, which writes it here.
#
# Written every deploy, because the values above are the deploy's, not the
# operator's -- the operator's own overrides come from the panel's env_vars,
# which the engine merges over this file.
cat > .env <<'EOF'
# Written by PanelAlpha. See ~/.panelalpha/xbackbone/ for the application key
# and the administrator credentials.
APP_ENV=production
APP_DEBUG=false
APP_INSTALLED=false
APP_TIMEZONE=UTC
APP_URL=http://localhost
LOG_CHANNEL=stderr

# Outside the checkout: /data is ~/.panelalpha/xbackbone, bind-mounted by the
# compose override, and survives the re-clone a redeploy does.
DB_CONNECTION=sqlite
DB_DATABASE=/data/xbb.db
FILESYSTEM_DISK=local

SESSION_DRIVER=database
CACHE_STORE=database
# Previews are generated by a queued job and this account runs no worker, so a
# `database` queue would mean uploads never get a thumbnail. XBackBone's
# installer is told the same thing and writes it back here.
QUEUE_CONNECTION=sync
EOF
chmod 600 .env

echo "[xbackbone] prepared: $(ls -d public core vendor 2>/dev/null | tr '\n' ' ')"
