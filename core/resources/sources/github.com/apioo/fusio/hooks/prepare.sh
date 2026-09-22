#!/bin/bash
# Account shell, after the clone and before the build.
#
# Everything here has to happen *before* `docker compose up`, because the
# generated compose file carries `env_file: - .env` and every line of that
# file becomes a real process environment variable in the container -- one
# that Symfony's Dotenv::populate() will then refuse to overwrite when the
# application loads .env again at runtime. A value corrected later, from the
# install stage, would be shadowed by the committed one for the life of the
# container. So the committed placeholders are fixed here, and only the one
# value that cannot be known yet (the database URL) is left out entirely, so
# that nothing shadows what the install stage writes.
set -e
cd ~/project

ENV_FILE=.env

# Secrets that must outlive the checkout. A rebuild re-clones over ~/project
# while the account's MySQL database -- and everything encrypted in it --
# stays exactly where it was, so a key stored beside the code would be
# regenerated against data it can no longer read.
#
# ~/.panelalpha is where they go, and the directory rather than the home
# itself because the home is root-owned and 755: an account cannot create a
# file directly in it. The engine creates ~/.panelalpha for every account and
# hands it to the account, and it is outside everything a deploy replaces.
STORE_DIR="$HOME/.panelalpha"
mkdir -p "$STORE_DIR"
KEY_STORE="$STORE_DIR/fusio-project-key"
PW_STORE="$STORE_DIR/fusio-admin-password"

# 1. The project key, which is the encryption key for every connection
#    credential Fusio stores in its database.
#
#    The repository commits APP_PROJECT_KEY="cee262a5a1bdd205cbfe0dfc2ad4eddf"
#    -- a real, published, 32-character value, the same one on every clone of
#    this repository in the world. configuration.php says what that means in
#    capitals: it encrypts the connection configs, and changing it later makes
#    the old ones unreadable. So it is generated per account, once, and reused
#    on every later deploy.
if [ ! -f "$KEY_STORE" ]; then
    ( umask 077; openssl rand -hex 16 > "$KEY_STORE" )
    chmod 600 "$KEY_STORE"
fi
PROJECT_KEY=$(cat "$KEY_STORE")

# 2. The first administrator's password, generated per account and never a
#    default. Fusio seeds only its own internal `Administrator` row with a
#    random password nobody is told; a human account is created from the
#    install stage, which needs a password that exists before it runs.
#
#    The umask is inside a subshell on purpose -- it has to cover the
#    redirection that creates the file, and it must not leak into the rest of
#    this script, where a 077 default would leave directories the engine
#    (www-data) cannot scan when it walks the tree for the document root.
if [ ! -f "$PW_STORE" ]; then
    ( umask 077; openssl rand -base64 18 | tr -d '/+=' > "$PW_STORE" )
    chmod 600 "$PW_STORE"
fi
( umask 077; cp "$PW_STORE" .panelalpha-admin-password )
chmod 600 .panelalpha-admin-password

# 3. .env, which the repository ships filled in for a developer's laptop.
#
#    Rewritten line by line rather than replaced, so an operator's own edits
#    to anything not named here survive a redeploy.
set_env() {
    key=$1
    value=$2
    if grep -q "^${key}=" "$ENV_FILE"; then
        # The value is written between double quotes, so the only characters
        # that need escaping are the three the dotenv parser still reads
        # inside them.
        escaped=$(printf '%s' "$value" | sed -e 's/[\\"$]/\\&/g')
        sed -i "s|^${key}=.*|${key}=\"${escaped}\"|" "$ENV_FILE"
    else
        printf '%s="%s"\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

set_env APP_PROJECT_KEY "$PROJECT_KEY"

# APP_ENV=dev and APP_DEBUG=true are what the repository ships. psx_debug is
# read straight from APP_DEBUG, and with it on, PSX renders the exception
# message, the file, the line and the full stack trace into the response of
# every failed request -- on a public URL. Nothing reads psx_env, but a
# hosted install should not describe itself as a development one either.
set_env APP_ENV prod
set_env APP_DEBUG false

# The engine's proxy terminates TLS and forwards the visitor's address in
# X-Forwarded-For. Fusio has a fail2ban-style firewall on by default
# (fusio_firewall_maxretry = 32 client errors in two minutes, banned for
# five), and with no trusted header configured every request looks like it
# came from the proxy -- so one visitor typing the wrong password enough
# times bans the proxy, which is every visitor. This is the header the
# engine's virtual host actually sets.
set_env APP_TRUSTED_IP_HEADER X-Forwarded-For

# The database URL is deliberately removed rather than corrected.
#
#    APP_CONNECTION="pdo-mysql://root:test1234@localhost/fusio"
#
# is the committed value and the whole of the HTTP 500 this recipe exists to
# fix. It cannot be corrected here because the database does not exist yet --
# the engine provisions it while it writes the compose file, after this hook
# -- and it must not be left in place, because env_file would then publish it
# into the container environment where it outranks anything the install stage
# writes back into .env. Deleting the line leaves the name unset in the
# container, and the install stage's .env is then the only thing that defines
# it, for Apache and for the CLI alike.
sed -i '/^APP_CONNECTION=/d' "$ENV_FILE"

# The mode is left as the repository ships it. .env sits at the top of the
# checkout and the document root is public/, so it is not web content at all,
# and the generated virtual host denies dotfiles besides; the file is read by
# the docker CLI (env_file), by PHP and by the account's own SFTP, all of
# which are this one uid, and a mode the deploy tightened by hand would only
# be one more thing to get wrong.

# 4. The web installer, which upstream tells you to delete.
#
#    public/install.php is inside the document root and answers 200. It is not
#    an open door -- a POST needs a lock file whose name is in the visitor's
#    own session, which is a proof-of-ownership check -- but it is a page that
#    describes the installation to anyone who asks, offers to rewrite .env and
#    to create an administrator, and README.md says to remove it once the
#    install is done. The install is done from the install stage here, so it
#    never has a reason to exist.
rm -f public/install.php

# 5. Directories Fusio writes into. `cache/` holds the compiled DI container
#    and the routing cache and is written on the first request; `log/` is
#    where psx_path_log points. Both are in the checkout with a placeholder
#    file, so this is about the mode, not the existence: the container runs as
#    this same account uid (the generated compose file says
#    `user: "<uid>:<gid>"`), so the account is the owner.
mkdir -p cache log public/apps
chmod 755 cache log public/apps
