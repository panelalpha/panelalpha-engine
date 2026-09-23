#!/bin/bash
# Account shell, after the clone and after the recipe's files/ are copied in,
# before detection and before the build.
#
# Two things that have to be true before anything is built or served, and that
# nothing later in the deploy can do:
#   1. the administrator password, which must outlive the checkout;
#   2. the data directory, which must exist before Docker makes the bind mount.
#
# Nothing here moves a compose file aside. Group Office ships none, and the
# glob that would do it is the trap in engine#166: by the time this hook runs
# the engine has already written overrides/docker-compose.override.yml into the
# project root under exactly that name, so `mv docker-compose.*` would take
# this recipe's own healthcheck with it and the deploy would still report
# success.
set -e
cd ~/project

say() { echo "[groupoffice] $*"; }

# ---------------------------------------------------------------- password --
#
# Every deploy re-clones over ~/project while the account's MySQL database --
# and the password hash in core_user -- stays exactly where it was, so a
# password generated beside the code would be a new password on every redeploy,
# matching nothing. ~/.panelalpha is where it goes, and a directory rather than
# the home itself because account homes are root-owned and 0755: an account
# cannot create a file directly in its own home. engine#173 also writes
# .env.default into the checkout 0644 and readable by every other tenant, which
# is the other reason nothing secret belongs in ~/project.
STORE_DIR="$HOME/.panelalpha"
mkdir -p "$STORE_DIR"
chmod 700 "$STORE_DIR"
PW_STORE="$STORE_DIR/groupoffice-admin-password"

if [ ! -f "$PW_STORE" ]; then
    # go\core\auth\Password rejects anything under 6 characters and Group
    # Office's own password policy module scores mixed classes, so this is
    # built to pass both on purpose: 20 base62 characters from openssl with a
    # digit and an upper-case letter appended, so the class mix never depends
    # on luck. The base64 punctuation is dropped because the login form has to
    # survive a human retyping it.
    (
        umask 077
        {
            openssl rand -base64 30 | tr -d '/+=\n' | cut -c1-20
            printf 'a7Z\n'
        } | tr -d '\n' > "$PW_STORE"
        printf '\n' >> "$PW_STORE"
    )
    chmod 600 "$PW_STORE"
    say "generated an administrator password in $PW_STORE"
fi

# The container cannot see ~/.panelalpha except through the /data mount, and
# /data is the account's data directory rather than its secret store -- so the
# value is copied into the application root as a dotfile instead. That is
# denied twice over: the generated vhost denies every path component starting
# with a dot, and this is read once, on the install stage, before Apache binds.
( umask 077; cp "$PW_STORE" www/.panelalpha-admin-password )
chmod 600 www/.panelalpha-admin-password

# ------------------------------------------------------------------- data --
#
# ~/.panelalpha/groupoffice is bind-mounted at /data by the compose override
# and holds everything that has to outlive a redeploy:
#
#   /data/files   file_storage_path. Every uploaded file, mail attachment,
#                 contact photo and blob: Blob::buildPath() is
#                 `<file_storage_path>/data/<xx>/<yy>/<id>`
#                 (go/core/fs/Blob.php:380-384). Also the compiled client-script
#                 cache, which panelalpha-install.php drops on every deploy.
#   /data/tmp     tmpdir. Sessions, the disk cache and upload staging.
#
# It has to exist before the container starts or Docker creates it as root, and
# the container runs as this account's uid (the generated compose file says
# `user: "<uid>:<gid>"`), which would then not be able to write to it.
DATA_HOME="$STORE_DIR/groupoffice"
mkdir -p "$DATA_HOME/files" "$DATA_HOME/tmp"
chmod 700 "$DATA_HOME" "$DATA_HOME/files" "$DATA_HOME/tmp"

say "prepared; data directory is $DATA_HOME"
