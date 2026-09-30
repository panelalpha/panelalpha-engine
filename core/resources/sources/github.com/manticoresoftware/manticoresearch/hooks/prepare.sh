#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml has been
# written, before the build.
#
# Two things the compose file cannot do for itself: write this account's search
# settings where the next clone will not delete them, and make the helper
# scripts executable. The admin login is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env; its password carries
# a symbol, which Manticore's strictest policy (`auth_password_policy = MEDIUM`,
# src/auth/auth_common.cpp) demands along with a lowercase, an uppercase and a
# digit.
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
# 1. The search settings.
#
# In ~/.panelalpha/manticore/, 0600 in a 0700 directory, which survives the
# clone: ProjectEnvironment::apply() republishes ~/project/.env as .env.default
# at mode 644 (engine#173).
STORE_DIR="${HOME}/.panelalpha/manticore"
ENV_STORE="${STORE_DIR}/manticore.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_STORE}" ]; then
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated.

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
    say "wrote this account's Manticore settings in ~/.panelalpha/manticore/manticore.env"
else
    say "reusing the settings already in ~/.panelalpha/manticore/manticore.env"
fi

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
# ~/.panelalpha/app-credentials.env at 0600.
MANTICORE_IMAGE=manticoresearch/manticore:29.9.0
EOF
chmod 600 .env

chmod 0755 panelalpha/manticore/*.sh 2>/dev/null || true
