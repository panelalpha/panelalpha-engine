#!/bin/bash
# Account shell, after the clone and after files/ and overrides/ are copied in,
# before detection and the build. Nothing here can know the database
# credentials, because this recipe brings its own database container and the
# password it uses is generated right here -- see step 2.
set -e
cd ~/project

# ---------------------------------------------------------------------------
# 1. Arcanist, which Phorge does not ship and cannot start without.
#
# scripts/init/lib.php:22 and support/startup/PhabricatorStartup.php:207 both
# do `@include_once $root.'arcanist/src/init/init-library.php'` and, when it
# fails, print "Put 'arcanist/' next to 'phorge/' on disk." and exit. That is
# not advice, it is the load path: `$libraries_root = dirname($phabricator_root)`
# is prepended to include_path, so the library is looked for one directory
# above the checkout. A single-repository deploy has no directory above the
# checkout that anything may write to -- /app's parent inside the container is
# the image's own `/`.
#
# So the recipe fetches it, and overrides/docker-compose.override.yml
# bind-mounts ./arcanist at /arcanist, which IS one directory above /app. The
# sibling upstream asks for is then real, and both the web SAPI and the CLI
# find it through the include_path Phorge sets itself. No PHUTIL_LIBRARY_ROOT
# and no include_path of our own: the environment variable is read from
# $_SERVER, which under mod_php holds Apache's subprocess environment rather
# than the container's, so it would work from `bin/storage` and silently not
# from the web.
#
# From we.phorge.it, not GitHub: Phorge's arcanist is a fork and the pair is
# developed together. Shallow, because nothing here reads its history, and
# re-cloned on every deploy because the clone wipes ~/project (engine defect
# #173) -- about 20s and 24 MB.
if [ ! -d arcanist/src ]; then
    rm -rf arcanist
    git clone --depth 1 https://we.phorge.it/source/arcanist.git arcanist
    echo "[phorge] cloned arcanist beside the checkout (mounted at /arcanist)"
fi

# ---------------------------------------------------------------------------
# 2. Secrets, in ~/.panelalpha/phorge, NOT in ~/project.
#
# Two of these have to survive a redeploy or the account breaks:
#
#   The MySQL root password is stored in the database volume, which outlives
#   the checkout. ~/project is wiped and re-cloned on every deploy (engine
#   defect #173), so a password kept there would be regenerated on the second
#   deploy while the volume still held the first one -- a healthy database
#   nothing can log into.
#
#   The administrator password is what the operator was given. Rotating it
#   silently on a redeploy is the same failure with a person on the other end.
#
# ~/.panelalpha already exists and is owned by the account (the engine puts its
# own build scratch there); the home directory above it is root-owned 755, so
# this is the only writable place outside ~/project. A 0700 subdirectory of our
# own rather than chmod on a directory the engine also uses.
#
# The umask is inside a subshell on purpose: it has to cover the redirection
# that creates each file, so nothing is ever briefly world-readable, and it
# must not leak into the rest of this script, where a 077 default would leave
# directories the engine (www-data) cannot scan.
#
# Engine defect #173 is also why none of this goes in .env: the engine writes
# .env.default 644, readable by every other tenant on the host.
SECRETS="$HOME/.panelalpha/phorge"
mkdir -p "$SECRETS"
chmod 700 "$SECRETS"

# PHORGE_DB_PASSWORD, not MYSQL_ROOT_PASSWORD: the container's own
# MYSQL_ROOT_PASSWORD is set by the engine (to the literal `app`) and is a
# different value with a different meaning. panelalpha/phorge-db-secure.php
# uses the engine's to log in once and this one to replace it.
if [ ! -f "$SECRETS/db.env" ]; then
    ( umask 077; printf 'PHORGE_DB_PASSWORD=%s\n' \
        "$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)" > "$SECRETS/db.env" )
    chmod 600 "$SECRETS/db.env"
fi

# Alphanumeric. It is passed to PhabricatorAuthPassword::setPassword() which
# takes anything, but it is also a value an operator copies out of a file and
# types into a login form, and a shell-quoted one in the setup script.
if [ ! -f "$SECRETS/admin-password" ]; then
    ( umask 077; openssl rand -base64 24 | tr -d '/+=' | cut -c1-20 > "$SECRETS/admin-password" )
    chmod 600 "$SECRETS/admin-password"
fi

# ---------------------------------------------------------------------------
# 3. .env, which has to exist before the container is created.
#
# The generated compose file carries `env_file: ['.env']` unconditionally and
# Compose reads it when the container is created, which is after this hook.
# Phorge ships no .env and no .env.example, so there is nothing for the engine
# to copy and the file would be missing.
#
# Deliberately empty of anything that matters. Everything Phorge needs to know
# is in conf/local/local.json, which the install stage writes, and the two
# secrets are in ~/.panelalpha/phorge, which is bind-mounted read-only at
# /panelalpha. A value here becomes a real process environment variable and is
# printed in the generated compose file.
if [ ! -f .env ]; then
    printf '# Written by the PanelAlpha phorge recipe. Phorge is configured\n# through conf/local/local.json, not through the environment.\n' > .env
fi

# ---------------------------------------------------------------------------
# 4. Directories Phorge writes into, which the clone does not contain.
#
# conf/local/ is where `bin/config set` writes local.json, which holds the
# MySQL root password.
#
# 755, and the 700 it obviously wants is measured and wrong: after this hook
# the engine walks the whole project tree as www-data (ProjectContext /
# PhpDocroot look for the document root), and a directory it cannot scandir
# fails the entire deploy with the raw PHP error
# `scandir(/home/<acct>/project/conf/local): Failed to open directory:
# Permission denied`. The secret is protected on the file instead --
# panelalpha/phorge-setup.sh chmods local.json 600 the moment `bin/config`
# has written it -- which is where it belongs anyway: the directory is not
# what is readable, the file is.
#
# 755 on the rest too, not 777: the container runs as this same account uid
# (the generated compose file says `user: "<uid>:<gid>"`), so Apache is the
# owner.
mkdir -p conf/local .phorge-repos .phorge-files
chmod 755 conf conf/local .phorge-repos .phorge-files

# ---------------------------------------------------------------------------
# 5. Nothing to move out of the way.
#
# Engine defect #166 mines a compose file left in the project root for runtime
# sidecars. Phorge ships none -- no docker-compose.yml, no compose.yaml, not
# anywhere in the tree -- so there is no glob here at all. Worth saying
# explicitly, because the glob other recipes use (`docker-compose.*.yml`) would
# catch overrides/docker-compose.override.yml, which the engine has ALREADY
# written into this directory by the time this hook runs, and quietly strip
# this recipe's database, its healthcheck and its /arcanist mount while the
# deploy still reported success.
