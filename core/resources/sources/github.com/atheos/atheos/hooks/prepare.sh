#!/bin/bash
set -e
cd ~/project

# Atheos is a web IDE: a browser-based editor that reads and writes files, runs
# git, and -- through its Macro component -- runs shell commands. Two things
# follow from that, and both have to be settled before the first request.
#
# 1. Where the files it writes live. WORKSPACE and DATA both default to
#    BASE_PATH . "/..." -- inside the checkout. A redeploy re-clones ~/project
#    after clearing it (engine#173), so an account would lose every file it had
#    written in the editor *and* its own login on the next deploy. Everything
#    persistent goes under ~/.panelalpha/atheos, which the compose override
#    bind-mounts at /data.
#
# 2. Who becomes the administrator. Atheos has no installed-marker: index.php
#    serves components/install/view.php whenever DATA/users.json.php and
#    DATA/projects.db.php are both absent, and components/install/process.php
#    is a POST endpoint that takes no session and creates the first user with
#    ["configure","read","write"] and userACL "full". On an account that has
#    just been given a public HTTPS name that is first-visitor-wins -- and the
#    prize is an editor that can write PHP into its own document root. The
#    password is generated here, once per account; panelalpha/atheos-install.php
#    spends it on the install stage, before Apache binds.
#
# files/data/users.json.php is already in place by the time this runs
# (AppConfigBootstrap::installFileSnippets() precedes runScripts()); see the
# note there for what it is for.

DATA_HOME="${HOME}/.panelalpha/atheos"

# ~ itself is root-owned 0755, so a new directory cannot be created there;
# ~/.panelalpha is created with the account and belongs to it. These are made
# before the mount so Docker never creates them as root.
mkdir -p "${DATA_HOME}/workspace" "${DATA_HOME}/data"
chmod 700 "${DATA_HOME}"

if [ ! -f index.php ] || [ ! -f common.php ] || [ ! -d components/install ]; then
    echo "[atheos] no index.php/common.php/components/install -- this is not an Atheos checkout" >&2
    exit 1
fi

# ---------------------------------------------------------- the administrator

# Written once per account and never rewritten. The user file lives in /data
# and survives the re-clone, so a regenerated password would stop matching the
# account already in it.
CREDENTIALS="${DATA_HOME}/admin-credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on first deploy. This is the Atheos administrator for
# this account -- sign in at https://<your-domain>/ .
#
# Atheos creates its first user from an unauthenticated POST endpoint and gives
# that user configure rights, which in a web IDE means shell commands through
# the Macro component and write access to the application own document root. It
# was run at deploy time instead, with these values, and is now closed. Change
# the password under the user menu and this file stops being interesting.
ATHEOS_ADMIN_USERNAME=admin
ATHEOS_ADMIN_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
EOF
    chmod 600 "${CREDENTIALS}"
fi

# ------------------------------------------------------------- the .htaccess

# Atheos ships a root .htaccess and Apache honours it (AllowOverride All on
# ${PA_DOCROOT}). Three of its rules do not do what they read as, because
# RedirectMatch takes a regex and "/data/*" means "/dat" followed by any
# number of "a"s -- it matches /data and /dataa and never
# /data/users.json.php:
#
#   RedirectMatch 403 ^/components/*.php$    matches /component.php
#   RedirectMatch 403 ^/data/*$              matches /data, not what is in it
#   RedirectMatch 403 ^/workspace/?$         matches the directory only
#
# The last two have nothing left to guard -- DATA and WORKSPACE are outside the
# document root now -- but the first one does. Atheos is served from its own
# repository root, so every component, trait, class and template is a URL, and
# only four .php files in the tree are entry points: index.php, controller.php,
# dialog.php and error.php at the root, plus components/transfer/download.php,
# which init.js fetches directly. Everything else is an include, and reaching
# an include over HTTP runs it outside the request it was written for --
# components/install/process.php being the one that matters, since it takes no
# session and creates the administrator.
#
# <DirectoryMatch> is not valid in .htaccess, so this is one file per
# directory. Only .php is denied under components/ and plugins/: the same
# directories hold the editor's JavaScript and CSS, and denying them outright
# takes the IDE with them.

deny_php_except() {
    local dir="$1" keep="$2"
    [ -d "${dir}" ] || return 0
    cat > "${dir}/.htaccess" <<EOF
# Written by PanelAlpha. These .php files are includes, not entry points.
<FilesMatch "^(?!${keep}\$).*\.php\$">
  Require all denied
</FilesMatch>
EOF
}

deny_php() {
    local dir="$1"
    [ -d "${dir}" ] || return 0
    cat > "${dir}/.htaccess" <<'EOF'
# Written by PanelAlpha. Nothing here is an entry point.
<FilesMatch "\.php$">
  Require all denied
</FilesMatch>
EOF
}

deny_all() {
    local dir="$1"
    [ -d "${dir}" ] || return 0
    cat > "${dir}/.htaccess" <<'EOF'
# Written by PanelAlpha. Nothing in here is web content.
Require all denied
EOF
}

# components/transfer/download.php is fetched by the browser (transfer/init.js)
# and checks the session itself; nothing else under components/ is.
deny_php_except components 'download\.php'
deny_php plugins
deny_all traits
deny_all classes
deny_all templates
deny_all vendor
deny_all data
# files/panelalpha/atheos-install.php refuses to run outside the CLI, but the
# generated vhost only denies files *named* panelalpha.*, not a directory of
# that name, and the document root here is the repository root.
deny_all panelalpha

if ! grep -q 'Written by PanelAlpha' .htaccess 2>/dev/null; then
    cat >> .htaccess <<'EOF'

##############################################################################
# Written by PanelAlpha.
#
# display_errors first, because on this image it is not cosmetic. The shared
# PHP base image runs display_errors=1 with no error_reporting set and no
# php.ini at all, and Atheos emits a notice from common.php:129 before it has
# sent anything else (TIMEZONE defaults to `false`, which reaches
# date_default_timezone_set() as ""). Printing that notice *sends the response
# headers*, so session_name() and session_start() four decades later both fail
# with "headers already sent", $_SESSION never exists, and
# traits/exchange.php:75 dies on array_key_exists(..., null):
#
#   Fatal error: Uncaught TypeError: array_key_exists(): Argument #2 ($array)
#   must be of type array, null given in /app/traits/exchange.php:75
#
# One notice takes the whole application down. config.php sets these too, but
# config.php is written by the install stage and this file is written by the
# after-clone hook, which runs earlier and unconditionally -- so this is the
# copy that holds when a stage command does not run (engine#169).
#
# <IfModule> because these directives only exist under mod_php, which is what
# this image runs; a server without it skips them rather than refusing to
# start.
##############################################################################
<IfModule mod_php.c>
  php_flag display_errors off
  php_flag log_errors on
</IfModule>

# common.php is the bootstrap every entry point includes and config.php is the
# generated configuration; index.php, controller.php, dialog.php and error.php
# stay reachable because they are the entry points. Both of the denied ones
# output nothing when executed, so this is about the day PHP is not running:
# a server that stops executing .php serves them as text. The per-directory
# files under components/, plugins/, traits/, classes/, templates/, vendor/
# and data/ do the rest.
<FilesMatch "^(common|config)\.php$">
  Require all denied
</FilesMatch>
EOF
fi

echo "[atheos] prepared: data at ${DATA_HOME}"
