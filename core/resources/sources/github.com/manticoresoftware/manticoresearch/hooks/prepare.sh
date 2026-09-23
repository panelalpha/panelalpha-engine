#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml has been
# written, before the build.
#
# Three things the compose file cannot do for itself: generate this account's
# admin credential and put it where the next clone will not delete it, tell the
# owner where to find it, and make the helper scripts executable.
set -e
cd ~/project

say() { echo "[manticore] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL, and a fork or a mirror answers to the same
# one. Nothing below reads the checkout -- the daemon comes from a published
# image -- but saying so turns a confusing result into one line in the log.
if [ ! -f src/searchdhttp.cpp ] || [ ! -f manticore.conf.in ]; then
    say "WARNING: this does not look like manticoresoftware/manticoresearch"
fi

# ---------------------------------------------------------------------------
# 1. The admin credential.
#
# engine#173: the deploy empties ~/project before every clone, so a guard on a
# file in there never fires and a regenerated password is silent data loss --
# the old one is already hashed into auth.json in the data volume and the daemon
# would keep honouring it while this file advertised a different one. And
# ProjectEnvironment::apply() republishes ~/project/.env as .env.default at mode
# 644, so a password may not be written there either.
#
# So it lives in ~/.panelalpha/manticore/, 0600 in a 0700 directory, which
# survives the clone. Generated once and never again.
#
# The password satisfies Manticore's strictest policy whatever the instance is
# configured with: ValidatePassword() under `auth_password_policy = MEDIUM`
# demands a lowercase, an uppercase, a digit and a non-alphanumeric character
# (src/auth/auth_common.cpp). base64 minus '/', '+' and '=' gives the first
# three from a random 24 bytes; the 'Aa1-' suffix guarantees all four and costs
# nothing next to the entropy in front of it. None of the characters left need
# quoting in an env file, in a shell, or in a URL's userinfo.
STORE_DIR="${HOME}/.panelalpha/manticore"
ENV_STORE="${STORE_DIR}/manticore.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_STORE}" ]; then
    ADMIN_USER=pa_admin
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')Aa1-"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated.
#
# This account's Manticore admin login. It is hashed into auth.json inside the
# manticore_data volume on first boot; changing it here alone does NOT change
# what the daemon accepts -- see ~/.panelalpha/manticore/credentials.txt.
MANTICORE_ADMIN_USER=${ADMIN_USER}
MANTICORE_ADMIN_PASSWORD=${ADMIN_PASSWORD}

# Read by the image's /etc/manticoresearch/manticore.conf.sh, which turns every
# searchd_* variable into a directive in the generated config.
#
# auth = 1 is the reason this recipe is shippable at all. With it, searchd
# refuses every HTTP request that carries no Authorization header -- 401, with
# a WWW-Authenticate: Basic challenge -- from the moment it starts and before
# any user exists (src/auth/auth_proto_http.cpp, CheckAuth() is called for
# every path; there is no unauthenticated endpoint). Without it, anyone who
# can reach port 9308 can create tables, insert and delete.
#
# (Written through an interpolating heredoc, so nothing in this file may
# contain a backtick or a bare $ -- the first version of it lost a line to
# command substitution inside a comment.)
searchd_auth=1

# 9308 is the HTTP API and the only port this stack publishes, so it is the one
# the domain proxies to. 9306 (MySQL protocol) and 9312 (binary) stay on the
# account's own compose network: the engine only ever creates an HTTP proxy
# rule, so neither can be published to the internet in any case, and both are
# behind the same authentication. The replication listener the image would add
# by default (9315-9325) is dropped -- this is one node.
searchd_listen=9306:mysql41|9308:http|9312
EOF
    )
    chmod 600 "${ENV_STORE}"
    say "generated this account's Manticore admin login in ~/.panelalpha/manticore/manticore.env"
else
    say "reusing the admin login already in ~/.panelalpha/manticore/manticore.env"
fi

# Read back rather than reused from the variables above, so the note below is
# right on a redeploy as well as on the first deploy.
ADMIN_USER=$(sed -n 's/^MANTICORE_ADMIN_USER=//p' "${ENV_STORE}" | head -1)
ADMIN_PASSWORD=$(sed -n 's/^MANTICORE_ADMIN_PASSWORD=//p' "${ENV_STORE}" | head -1)

# ---------------------------------------------------------------------------
# 2. ~/project/.env -- what compose interpolates, and nothing else.
#
# Rewritten every deploy because everything in it is derived and none of it is
# secret, which makes the engine republishing it at 644 a non-event. The
# credential reaches the containers through the second env_file instead.
cat > .env <<EOF
# Written by PanelAlpha. Compose reads this for \${...} substitution. Nothing
# secret belongs here: the engine copies this file to .env.default at mode 644
# (engine#173). This account's Manticore login is in
# ~/.panelalpha/manticore/manticore.env at 0600.
MANTICORE_IMAGE=manticoresearch/manticore:29.9.0
EOF
chmod 600 .env

chmod 0755 panelalpha/manticore/*.sh 2>/dev/null || true

# ---------------------------------------------------------------------------
# 3. Where a human is pointed.
#
# Beside the credential it describes, not in ~/project, which the next deploy
# empties.
#
# The account's domain is deliberately NOT filled in. Nothing in the hook's
# environment carries it, and the obvious guess -- ~/<domain>, which sits
# beside ~/project -- does not exist yet: measured, the engine creates it about
# three seconds after the prepare stage runs, so on a first deploy the lookup
# finds nothing and only a redeploy would fill it in. A note whose URL is right
# on some deploys and a placeholder on others is worse than one that is always
# a placeholder, so this asks the reader for the name the panel already shows
# them.
cat > "${NOTE}" <<EOF
Manticore Search (manticoresoftware/manticoresearch), deployed by PanelAlpha.

Manticore is a search server with no web interface of its own. What your domain
serves is its JSON API, and every request to it needs these credentials --
including the front page, which is why a browser asks you to log in.

  API base      https://<your domain>/   (the domain shown for this app in the panel)
  Username      ${ADMIN_USER}
  Password      ${ADMIN_PASSWORD}

Anything without them is refused with 401. Try it:

  curl -u '${ADMIN_USER}:${ADMIN_PASSWORD}' -X POST https://<your domain>/cli \\
       -d 'create table products(title text, price float)'

  curl -u '${ADMIN_USER}:${ADMIN_PASSWORD}' https://<your domain>/insert \\
       -H 'Content-Type: application/json' \\
       -d '{"table":"products","doc":{"title":"red running shoes","price":79.0}}'

  curl -u '${ADMIN_USER}:${ADMIN_PASSWORD}' https://<your domain>/search \\
       -H 'Content-Type: application/json' \\
       -d '{"table":"products","query":{"match":{"title":"shoes"}}}'

A bearer token, if you would rather not send the password on every call:

  curl -u '${ADMIN_USER}:${ADMIN_PASSWORD}' -X POST https://<your domain>/token

Then 'Authorization: Bearer <token>' instead of -u. One token exists per user;
asking again replaces it, and every client using the old one stops working.

The MySQL protocol (port 9306) and the binary protocol (9312) are also running
and also need these credentials, but only inside this account's own Docker
network -- PanelAlpha can publish an HTTP port to your domain and nothing else.
From a container in the same compose project:

  mysql -h manticore -P 9306 -u '${ADMIN_USER}' -p

More users, each with their own password and bearer token:

  create user 'readonly' identified by 'Somepassword123-x'
  grant read on '*' to 'readonly'

Changing a password is NOT a matter of editing manticore.env. The daemon reads
the hash it was given on first boot from auth.json inside the manticore_data
volume, and only SQL changes it:

  cd ~/project
  docker compose exec manticore mysql -h127.0.0.1 -P9306 -u'${ADMIN_USER}' \\
      -p'${ADMIN_PASSWORD}' \\
      -e "SET PASSWORD 'new-password' FOR '${ADMIN_USER}'"

Then put the same new password in ~/.panelalpha/manticore/manticore.env, or the
next deploy's readiness check will fail and take the deploy with it. Note that
the statement is SET PASSWORD; Manticore has no ALTER USER, quotes around every
username and password are required, and a bearer token issued earlier keeps
working until you reissue it with TOKEN.

Send user-management statements over the MySQL protocol as above, not through
the HTTP /cli endpoint: /cli answers "Query OK, 0 rows affected" to a
CREATE USER or SET PASSWORD that the daemon never applied (measured on 29.9.0).

Your data is in the manticore_data Docker volume, not in ~/project. The deploy
empties ~/project on every run; the volume and this file both survive it.

Docs: https://manual.manticoresearch.com/
EOF
chmod 600 "${NOTE}"

say "credentials and notes in ~/.panelalpha/manticore/credentials.txt"
