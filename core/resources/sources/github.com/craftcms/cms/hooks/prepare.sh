#!/bin/bash
# Account shell, after the clone and after files/ has been installed, before
# detection and the build.
#
# craftcms/cms is the Craft source package, not a site. Everything that makes
# it servable -- web/index.php, the `craft` console script, config/ and
# templates/ -- arrives from files/ in this recipe, because the repository has
# none of it. What is left for this hook is the four things a file snippet
# cannot do: stop the engine from compiling Craft's own control panel from
# source, keep the per-account secrets somewhere a redeploy does not delete,
# create the directories Craft writes into, and make sure nothing that reaches
# the container as an environment variable carries a secret in a file the
# engine will publish at 644.
set -e
cd ~/project

say() { echo "[craft] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL, and a fork or a mirror can answer to the
# same one. Everything below assumes the craftcms/cms layout -- bootstrap/,
# src/, lib/yii2 -- and web/index.php from files/ requires bootstrap/web.php by
# path. Saying so here turns a confusing 500 into one line in the deploy log.
if ! grep -q '"name"[[:space:]]*:[[:space:]]*"craftcms/cms"' composer.json 2>/dev/null; then
    say "WARNING: composer.json is not craftcms/cms; this recipe may not fit"
fi
[ -f bootstrap/bootstrap.php ] || say "WARNING: bootstrap/bootstrap.php is missing"

# ---------------------------------------------------------------------------
# 1. package.json, out of the way.
#
# This is the single most expensive thing the engine gets wrong here, and it is
# not wrong about anything: HostCompile::runForPhp() runs a PHP project's own
# package manager whenever package.json declares a `build` script, because for
# a Laravel or Symfony app that is how public/build gets made. craftcms/cms
# declares one -- `webpack --node-env=production` over 66 asset bundles, with a
# `prebuild` that runs `prettier --write .` across the whole repository first.
#
# It is also entirely unnecessary. The built control panel is *committed*:
# src/web/assets/*/dist holds 28 MB of compiled JS, CSS, fonts and images in
# git, which is what a published craftcms/cms tarball serves. The webpack build
# is how Pixel & Tonic regenerate those files, not something a site does.
#
# Renamed rather than deleted, so an operator can see what was skipped, and to
# a name that is not `package.*.json` -- nothing globs for this one.
for f in package.json package-lock.json; do
    if [ -f "$f" ] && [ ! -f "${f}.upstream-dev" ]; then
        mv "$f" "${f}.upstream-dev"
    fi
done
say "moved the repository's npm build aside (the control panel is committed prebuilt)"

# ---------------------------------------------------------------------------
# 2. .env, with nothing in it worth reading.
#
# The repository's .env.example is the webpack dev server's
# (DEV_SERVER_PUBLIC=http://localhost:8085/), and EnvExampleCopies would copy
# it to .env for want of anything better, from where `env_file: - .env` in the
# generated compose file would publish those three names into the container.
# Harmless, but it describes a development setup that is not happening here.
#
# The file still has to exist, because the generated compose file names it and
# Compose V2 refuses to start a project whose env_file is missing. So it is
# written empty-but-for-a-comment, and stays that way: ProjectEnvironment::
# apply() copies whatever is in .env to .env.default at mode **644**, which is
# why no secret this recipe generates is ever written here. They go to
# ~/.panelalpha/craft.env (step 3), which reaches the container through a
# second env_file named by overrides/docker-compose.override.yml.
rm -f .env.example
cat > .env <<'EOF'
# Written by PanelAlpha. Craft is configured from config/general.php,
# config/db.php and the container's environment, so there is nothing here.
#
# Do not put secrets in this file: the engine copies it to .env.default with
# mode 644. The account's generated values live in ~/.panelalpha/craft.env.
#
# Anything added here does reach Craft: CRAFT_* names map onto Craft's own
# settings (CRAFT_DEV_MODE, CRAFT_DISALLOW_ROBOTS, CRAFT_DB_TABLE_PREFIX, ...).
EOF
chmod 644 .env

# ---------------------------------------------------------------------------
# 3. The values that must outlive the checkout.
#
# GitRepository::cloneConfiguredRepository() empties ~/project before every
# deploy, and the account's MySQL database survives it. A key regenerated on
# the wrong side of that is a key that no longer matches its data, so these
# live in ~/.panelalpha -- the engine's own per-account directory, outside
# everything a deploy replaces. The directory rather than $HOME itself,
# because the home is root-owned and 0755 and an account cannot write into it.
#
# CRAFT_SECURITY_KEY is the one that matters. Craft hashes and encrypts with
# it: session identities, the `rememberMe` cookie, password-reset and
# verification codes, and anything a field or plugin stores encrypted. Replace
# it against a database that is still there and none of that is readable
# again. `setup/security-key` -- which `install` runs through `setup/keys` --
# would generate one per deploy and write it to .env, which is exactly the
# 644-published file above; supplying it from here means that branch never
# fires.
#
# CRAFT_APP_ID keys the session cookie, the cache and the mutex locks; the
# same `setup/keys` writes that one too, for the same reason.
STORE_DIR="$HOME/.panelalpha"
mkdir -p "$STORE_DIR"
STORE="$STORE_DIR/craft.env"
# Everything Craft keeps that is data rather than code: the license key, the
# storage/ tree (rebrand images, backups, caches) and a durable place for an
# asset volume. Mounted into the container by
# overrides/docker-compose.override.yml at /pa, /app/storage and
# /app/web/uploads. Created here because a bind mount whose source does not
# exist is created by the daemon as root, which the container (running as
# this account) then cannot write.
mkdir -p "$STORE_DIR/craft" "$STORE_DIR/craft/storage" "$STORE_DIR/craft/uploads"
chmod 755 "$STORE_DIR/craft" "$STORE_DIR/craft/storage" "$STORE_DIR/craft/uploads"

if [ ! -f "$STORE" ]; then
    # The umask is inside a subshell on purpose: it has to cover the
    # redirection that creates the file, so the values are never briefly
    # world-readable, and it must not leak into the rest of this script -- a
    # stray 077 here leaves directories the engine (www-data) cannot scan
    # while it walks the tree looking for the document root.
    (
        umask 077
        cat > "$STORE" <<EOF
# Generated once by the PanelAlpha Craft CMS recipe. Read into the container as
# a second env_file (see overrides/docker-compose.override.yml). Kept out of
# ~/project, which every deploy re-clones from scratch, and out of .env, which
# the engine copies to a world-readable .env.default.
#
# CRAFT_SECURITY_KEY encrypts and hashes stored data. Changing it against an
# existing database is not recoverable.
CRAFT_SECURITY_KEY=$(openssl rand -base64 32 | tr -d '\n')
CRAFT_APP_ID=CraftCMS--$(openssl rand -hex 8)
# The first administrator. Created non-interactively from the install stage,
# so the install never sits open on /index.php?p=admin/install waiting for
# whoever arrives first to claim it.
PA_CRAFT_ADMIN_USERNAME=admin
PA_CRAFT_ADMIN_EMAIL=admin@example.com
PA_CRAFT_ADMIN_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
PA_CRAFT_SITE_NAME=Craft CMS
EOF
    )
    chmod 600 "$STORE"
    say "generated the account's security key and administrator password in ~/.panelalpha/craft.env"
fi

# Where a human is pointed. ~/project is re-cloned every deploy, so this is a
# copy of the stored value rather than the value itself -- the same password on
# every redeploy, and the one the database actually holds.
(
    umask 077
    {
        echo "# Written by PanelAlpha. Craft has no sign-up page and its installer"
        echo "# creates the first administrator; this is the one it created."
        grep -E '^PA_CRAFT_ADMIN_(USERNAME|EMAIL|PASSWORD)=' "$STORE" | sed 's/^PA_CRAFT_//'
        echo "# Control panel: <your domain>/admin"
    } > .panelalpha-admin-password
)
chmod 600 .panelalpha-admin-password

# ---------------------------------------------------------------------------
# 4. The directories Craft writes into.
#
# bootstrap/bootstrap.php refuses to boot -- 503, before any of Craft is
# loaded -- when storage/ is missing or not writable, and on a web request it
# proves config/ is writable by writing a temporary license key into it.
# web/cpresources is where the control panel publishes its own JS and CSS on
# first use.
#
# 755 rather than tighter: the container runs as this account's uid, and the
# engine (www-data) walks the tree looking for the document root and stops the
# deploy on a directory it cannot open.
# storage/ and web/uploads are shadowed by the bind mounts in
# overrides/docker-compose.override.yml, so inside the container these are the
# mount points rather than these directories. They are still created, so that a
# Craft run outside the container -- an SSH session, an operator's `php craft`
# -- has somewhere to write and boots the same way.
mkdir -p storage web/cpresources web/uploads config templates
chmod 755 storage web web/cpresources web/uploads config templates
