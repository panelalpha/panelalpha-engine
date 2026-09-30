#!/bin/bash
# Account shell, after the clone and before the build. Runs on every deploy.
#
# Four jobs. None of them can be done from inside the container, and none of
# them can be left to the checkout:
#
#   1. create the data directory the compose override bind-mounts, *before*
#      compose does -- a missing host path becomes a root-owned directory the
#      account cannot write to
#   2. generate the admin password, outside ~/project so it survives the clone
#   3. add four rules to the .htaccess upstream ships, one of which repairs
#      upstream's own broken .well-known redirect
#   4. move a compose file out of the checkout if one ever appears, by name
#
# Nothing here opens the database: the schema lives in
# panelalpha-baikal-setup.php, which runs inside the container on the install
# and upgrade stages.
set -e
cd ~/project

STORE="${HOME}/.panelalpha"
# Account homes are root-owned 755, so this has to be created rather than
# written into $HOME directly. It is also the only place anything survives a
# deploy: GitRepository::cloneConfiguredRepository empties ~/project before
# every clone (engine #173).
mkdir -p "${STORE}"
chmod 700 "${STORE}"

# ---------------------------------------------------------------------------
# 1. The data directory.
#
# Everything the account owns that is not source code lives here:
# config/baikal.yaml, Specific/db/db.sqlite (the calendars, the address books,
# the users) and Specific/INSTALL_DISABLED. The compose override bind-mounts it
# at /data and points BAIKAL_PATH_CONFIG and BAIKAL_PATH_SPECIFIC there --
# which are upstream's own environment variables
# (Core/Frameworks/Flake/Framework.php:180-195), not something this recipe
# invented. Their fallback is `config/` and `Specific/` inside the repository,
# which the clone deletes, so without this the account loses every calendar it
# has on each redeploy.
#
# Created here and not by Docker, and that is the point of doing it in the
# hook: compose creates a missing bind-mount source itself, owned by root and
# mode 755, and the app container runs as the account uid and could then
# neither write the SQLite file nor create its -journal beside it.
DATA="${STORE}/baikal"
mkdir -p "${DATA}/config" "${DATA}/Specific/db"
chmod 700 "${DATA}" "${DATA}/config" "${DATA}/Specific" "${DATA}/Specific/db"

# ---------------------------------------------------------------------------
# 2. The administrator password.
#
# There is exactly one secret here, which is the difference SQLite makes: no
# database password, no encryption key the account has to keep (the setup
# script mints that into baikal.yaml itself). It is the engine's
# (`credentials:` in panelalpha.yaml): BAIKAL_ADMIN_PASS in
# ~/.panelalpha/app-credentials.env, which the app container reads through
# env_file: rather than .env, and 24 letters and digits, since CalDAV clients
# send it hashed as md5('admin:<realm>:<pass>') in an HTTP Digest header, where
# a stray ':' is ambiguous.

# ---------------------------------------------------------------------------
# 3. The document root's .htaccess.
#
# Upstream ships html/.htaccess and it is load-bearing: the
# `E=HTTP_AUTHORIZATION` rewrite is what lets Digest credentials reach PHP on a
# CGI SAPI, and the two `Redirect 308` lines are RFC 6764 discovery. So this
# appends rather than replaces. The marker makes it idempotent even though the
# clone restores the original file every deploy.
MARK_BEGIN="# --- PanelAlpha Baikal recipe: begin ---"
MARK_END="# --- PanelAlpha Baikal recipe: end ---"
if grep -qF "${MARK_BEGIN}" html/.htaccess 2>/dev/null; then
    sed -i "\|${MARK_BEGIN}|,\|${MARK_END}|d" html/.htaccess
fi
# Quoted heredoc: everything below is Apache configuration, and `$` in a
# regular expression must not be read by the shell.
#
# Every rule here is mod_alias, mod_setenvif or mod_headers, and not one is
# mod_rewrite. That is measured, not stylistic: upstream's own block above
# ends with
#
#     RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},L]
#
# which matches every request and carries [L], so a mod_rewrite rule appended
# after it never runs. A `RewriteRule ^res/.*\.html$ - [F]` written here
# returned 200, and the same path as a RedirectMatch returns 403. All three
# modules are enabled in the shared base image (checked in
# /etc/apache2/mods-enabled).
cat >> html/.htaccess <<'EOF'
# --- PanelAlpha Baikal recipe: begin ---

<IfModule mod_alias.c>
    # The install wizard, denied outright.
    #
    # panelalpha-baikal-setup.php creates Specific/INSTALL_DISABLED on the
    # install stage, and with that file present this URL answers "Installation
    # was already completed." and exits -- so the application already closes
    # itself. This says it a second way, because the branch above that one does
    # not check INSTALL_DISABLED at all: when config/baikal.yaml's
    # configured_version differs from the code's BAIKAL_VERSION,
    # install/index.php renders UpgradeConfirmation, and ?upgradeConfirmed then
    # runs VersionUpgrade -- a schema migration, on an unauthenticated request,
    # by upstream's deliberate choice ("No auth check: ... upgrading is safe",
    # Core/Frameworks/BaikalAdmin/WWWRoot/install/index.php:97). Every deploy
    # re-clones master, so that window opens by itself the first time upstream
    # bumps the version. The install/upgrade stage command runs the same
    # upgrade before Apache binds, which is what makes denying the URL safe
    # rather than a lock-out.
    RedirectMatch 403 ^/admin/install(/.*)?$

    # html/res/core is a symlink to Core/Resources/Web, whose Baikal and
    # BaikalAdmin entries are symlinks into the framework's Resources
    # directories. Those hold the CSS, JS and images the pages reference -- and
    # also 17 .html page templates, which Flake reads off the filesystem and no
    # rendered page ever requests. Checked: style.css still answers 200.
    RedirectMatch 403 ^/res/.*\.html$
</IfModule>

# The RFC 6764 discovery paths, with a Location a client can actually follow.
#
# Upstream's own two lines above --
#
#     Redirect 308 /.well-known/caldav /dav.php
#
# -- are mod_alias, and mod_alias builds an absolute URL from what the server
# believes it is. Behind the engine's proxy that is `http` and port 8000, so
# the account answers
#
#     Location: http://<domain>:8000/dav.php
#
# which is the container's own port on the public hostname: not published, not
# TLS, and measured from outside as "Failed to connect ... port 8000". Every
# client that starts from /.well-known -- which is every client that is given
# only a domain -- fails there.
#
# A relative Location is legal (RFC 7231 section 7.1.2) and is resolved by the
# client against the scheme and host it actually used, so it cannot be wrong
# about either. mod_headers rewrites the one upstream set; `always` is required
# because a 308 is generated by Apache and its headers live in
# err_headers_out, not headers_out. SetEnvIf keys it to the two exact paths so
# no other response is touched. Measured: 308 with `Location: /dav.php`,
# following it lands on https://<domain>/dav.php and answers 401.
<IfModule mod_setenvif.c>
    SetEnvIf Request_URI "^/\.well-known/(caldav|carddav)$" PA_WELLKNOWN_DAV=1
</IfModule>
<IfModule mod_headers.c>
    Header always set Location "/dav.php" env=PA_WELLKNOWN_DAV
</IfModule>
# --- PanelAlpha Baikal recipe: end ---
EOF
chmod 644 html/.htaccess

# ---------------------------------------------------------------------------
# 4. Compose files the repository does not have today.
#
# Baikal 0.12.1 ships no docker-compose.yml, no compose.yaml and no Dockerfile
# -- checked, including .github/. Were one to appear, the php strategy would
# mine it for backing services (engine #166) and this account would grow
# whatever database upstream's CI uses. Moved by name and never by a
# `docker-compose.*.yml` glob, which matches this recipe's own override and
# would take the healthcheck and the /data mount with it while the deploy still
# reported success.
mkdir -p "${STORE}/upstream-compose"
for f in docker-compose.yml docker-compose.yaml compose.yml compose.yaml; do
    [ -f "$f" ] || continue
    mv -f "$f" "${STORE}/upstream-compose/"
    echo "[baikal] moved $f out of the checkout (engine #166)"
done

echo "[baikal] prepare finished; data directory ${DATA}"
