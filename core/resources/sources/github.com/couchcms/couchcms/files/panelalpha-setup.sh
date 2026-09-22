#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Idempotent: the upgrade stage replays it on every redeploy.
#
# This file sits in the project root rather than in a panelalpha/ directory
# because the project root *is* the document root here -- and the generated
# vhost denies exactly two shapes of engine file by name:
#
#     <FilesMatch "^(?:docker-compose\.ya?ml|panelalpha[-.])">
#
# so `panelalpha-setup.sh` and `panelalpha-install.php` are denied while
# `panelalpha/setup.sh` would be served. (resources/deploy/templates/apache-vhost.stub)
set -e
cd /app

log() { echo "[couchcms] $*"; }

# `check` prints two shell assignments and nothing else -- but it is eval'd, so
# the filter is not a formality: anything else that reached stdout would be run
# by this shell. PHP is told to put its own output on stderr as well.
couch_check() {
    php /app/panelalpha-install.php check | grep -E '^(installed|template)=[01]$'
}

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    log "no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# 1. couch/config.php -- the database the engine provisioned and the account's
#    own URL. Written every time rather than only when missing: every deploy
#    re-clones over ~/project, so there is never a file here to preserve, and
#    the values the container was handed are by definition the ones that work.
php /app/panelalpha-install.php config

# 2. The installation itself.
#
#    couch/install.php has no gate on it: header.php hands any request to it
#    whenever the k_couch_version row is missing, and it creates every table
#    and the super-admin from whatever it is posted. Left to a visitor on a
#    public URL that is first-visitor-wins. It is run here instead, in a CLI
#    process, with a password generated per account by hooks/prepare.sh -- and
#    once it has run, the window is closed for good, because the version row is
#    what header.php looks for.
eval "$(couch_check)"

if [ "${installed}" = "1" ]; then
    log "already installed; leaving the database alone"
else
    log "running the CouchCMS installer for super-admin '${COUCH_ADMIN_USER:-admin}'"
    out=$(php /app/panelalpha-install.php install) || {
        log "the installer exited non-zero" >&2
        exit 1
    }

    # install.php renders its result rather than setting an exit status, and
    # ends in die() either way.
    if ! printf '%s' "$out" | grep -q 'Installation successful'; then
        log "installation failed. CouchCMS said:" >&2
        printf '%s' "$out" | sed -n 's/.*<h2>\(.*\)<\/h2>.*/\1/p' >&2
        printf '%s' "$out" | grep -o 'k_install_error[^<]*' >&2 || true
        exit 1
    fi

    eval "$(couch_check)"
    if [ "${installed}" != "1" ]; then
        log "the installer reported success but the schema is not there" >&2
        exit 1
    fi
    log "installed; the super-admin password is in ~/.panelalpha/couchcms-admin-password"
fi

# 3. The starter template.
#
#    CouchCMS registers a template -- inserts its couch_templates row and
#    creates its master page -- only for a logged-in super-admin
#    (couch/page.php:181). A visitor who is the first to reach an unregistered
#    template is redirected to the admin login instead of being served the
#    page, so `/` would answer a redirect until somebody logged in. This
#    request is that first super-admin visit, made from the deploy.
#
#    Run on every deploy, not only the first: header.php also runs
#    couch/upgrade.php from here when the checkout is newer than the schema,
#    which is how CouchCMS migrates -- otherwise the first visitor after a
#    version bump would be paying for the ALTER TABLEs.
php /app/panelalpha-install.php render > /dev/null

eval "$(couch_check)"
if [ "${template}" != "1" ]; then
    log "the starter template did not register; / would redirect to the login" >&2
    exit 1
fi

log "setup finished"
