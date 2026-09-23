#!/bin/bash
# Account shell, after the clone and before detection.
#
# There is almost nothing to fix in this checkout -- CouchCMS is a plain PHP
# application that wants a MySQL database and a config file, and the recipe's
# files/ have already been laid down beside couch/ by the time this runs. What
# is left is the one thing that must exist *before* anything is served and
# cannot be regenerated later: the super-admin password.
set -e
cd ~/project

# The password has to outlive the checkout. Every deploy re-clones over
# ~/project while the account's MySQL database -- and the password hash in it
# -- stays exactly where it was, so a password generated beside the code would
# be a new password on every redeploy, matching nothing.
#
# ~/.panelalpha is where it goes, and the directory rather than the home
# itself because account homes are root-owned and 0755: an account cannot
# create a file directly in its own home.
STORE_DIR="$HOME/.panelalpha"
mkdir -p "$STORE_DIR"
PW_STORE="$STORE_DIR/couchcms-admin-password"

if [ ! -f "$PW_STORE" ]; then
    # The umask is inside a subshell on purpose: it has to cover the
    # redirection that creates the file, and it must not leak into the rest of
    # this script, where a 077 default would leave directories the engine
    # (www-data) cannot scan when it walks the tree for the document root.
    #
    # tr drops the three base64 characters CouchCMS's own login form would have
    # to survive being retyped; what is left is 24 characters of the alphabet
    # its `min_len=5` validator is happy with.
    ( umask 077; openssl rand -base64 24 | tr -d '/+=' > "$PW_STORE" )
    chmod 600 "$PW_STORE"
fi

# The container cannot see ~/.panelalpha -- only ~/project is bind-mounted at
# /app -- so the value is copied in as a dotfile. The generated vhost denies
# every path component starting with a dot except .well-known, so this is not
# web-readable even though the document root is the project root:
#
#     <FilesMatch "^\.(?!well-known)"> Require all denied </FilesMatch>
( umask 077; cp "$PW_STORE" .panelalpha-admin-password )
chmod 600 .panelalpha-admin-password

# couch/uploads and couch/cache are written by the application at runtime. They
# are in the checkout already, so this is about the mode and not the existence;
# the container runs as this same account uid (the generated compose file says
# `user: "<uid>:<gid>"`), so the account is the owner either way.
mkdir -p couch/uploads couch/cache
chmod 755 couch/uploads couch/cache
