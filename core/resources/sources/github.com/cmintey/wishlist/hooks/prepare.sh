#!/bin/bash
# Runs on the account after the clone, before detection.
#
# Two things a Wishlist checkout cannot say: where the wish lists live, and who
# owns the installation.
#
# Upstream's compose file bind-mounts ./data and ./uploads -- inside the
# checkout. A redeploy clears and re-clones ~/project (engine#173), so every
# list, item, claim and uploaded image would be destroyed by the next deploy.
# Measured on the stock deploy: both directories were created inside
# ~/project, root-owned, with prod.db in one of them. They live in
# ~/.panelalpha/wishlist instead; ~ is root-owned 0755 and nothing can be
# created there, while ~/.panelalpha is created with the account and belongs to
# it.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/wishlist"

say() { echo "[panelalpha] wishlist: $*"; }

mkdir -p "${DATA_HOME}/data" "${DATA_HOME}/uploads"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# The account
# ---------------------------------------------------------------------------
# Wishlist ships no installer and no seeded user. routes/setup-wizard/
# +page.server.ts serves its wizard to anybody at all while the user table is
# empty, and routes/signup/+page.server.ts gives the first account created
# Role.ADMIN -- so a stock deploy on a public HTTPS address belongs to whoever
# finds it first. files/panelalpha/install.mjs creates the account from this
# file before the server binds, and turns public signup off in the same
# transaction.
#
# Written once and never rewritten: install.mjs is a no-op once a user exists,
# so a regenerated password would stop matching a database that survived the
# redeploy.
#
# And only while there is no database. A credentials file that went missing
# from an installation people are already using would otherwise be rewritten
# with a password that matches nothing -- install.mjs is a no-op once a user
# exists, so nobody would ever be told it does not work. An empty file is
# created instead, to say so and to give the compose mount something to bind.
CREDENTIALS="${DATA_HOME}/credentials"
if [ ! -f "${CREDENTIALS}" ] && [ -f "${DATA_HOME}/data/prod.db" ]; then
    umask 077
    cat > "${CREDENTIALS}" <<'EOF'
# This installation already had a database when PanelAlpha looked, and its
# credentials file was gone, so there is nothing to put here: the account was
# created on an earlier deploy and only its owner knows the password. Reset it
# from the running application, or from another admin account.
EOF
    chmod 600 "${CREDENTIALS}"
    say "the database predates this credentials file; left it empty rather than write a password that would not work" >&2
fi
if [ ! -f "${CREDENTIALS}" ]; then
    # No characters that need quoting in a shell, a URL or a form.
    password=$(LC_ALL=C tr -dc 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789' < /dev/urandom | head -c 20)
    if [ ${#password} -ne 20 ]; then
        say "could not generate a password; the install step will leave signup open" >&2
        exit 1
    fi
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on the first deploy. This is the Wishlist admin account
# for this installation -- sign in at https://<your-domain>/login .
#
# Wishlist's setup wizard hands the installation to whoever reaches it first
# while no account exists, and public signup is on by default, so this account
# was created at deploy time and signup was turned off. Turn it back on under
# Admin > Settings if you want people to sign up for themselves; you can invite
# them from there either way. Change the password under Account and this file
# stops being interesting.
WISHLIST_USERNAME=admin
WISHLIST_EMAIL=admin@localhost
WISHLIST_PASSWORD=${password}
EOF
    say "generated the admin password; it is in ~/.panelalpha/wishlist/credentials"
fi
chmod 600 "${CREDENTIALS}"

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------
# Written here so that ProjectEnvironment::apply() finds a real .env and keeps
# it, instead of copying .env.example -- which is the whole of why the stock
# deploy failed. That file ships `ORIGIN=` with no value, compose loads it
# through `env_file:`, and ORIGIN then reaches the container *set and empty*.
# @sveltejs/adapter-node validates it:
#
#     Error: Invalid ORIGIN: ''. ORIGIN must be a valid URL with http:// or
#     https:// protocol.
#
# `node build` exits 1, the container restart-loops, and the proxy answers 502.
# ORIGIN is deliberately absent from this file: the compose `environment:`
# block carries it, because only the engine knows the account's public address.
#
# 0644, the mode the engine writes its own .env with. Nothing in it is secret
# -- the password is in ~/.panelalpha/wishlist/credentials -- and a 0600 .env
# is invisible to EnvSidecars::variableMap(), which reads it with plain
# file_get_contents() as www-data and silently falls back to .env.example
# (engine#186), putting `ORIGIN=` right back.
cat > .env <<'EOF'
# Written by PanelAlpha. The admin account for this installation is in
# ~/.panelalpha/wishlist/credentials .
#
# ORIGIN is not here: it is set in docker-compose.yml to this account's public
# address, because a compose `environment:` value outranks `env_file:` and the
# checkout has no way to know the address. Edit it there if you serve Wishlist
# under a different name. Leaving it empty is not an option -- adapter-node
# rejects an empty ORIGIN and the server will not start.

# Hours until signup and password reset tokens expire
TOKEN_TIME=72
# The currency to use when a product search does not return a currency
DEFAULT_CURRENCY=USD
# trace | debug | info | warn | error | fatal | silent
LOG_LEVEL=info
# Maximum image size that can be uploaded, in bytes
MAX_IMAGE_SIZE=5000000
EOF
chmod 644 .env

say "prepared; data and uploads live in ${DATA_HOME}"
