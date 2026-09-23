#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Idempotent: the upgrade stage replays it on every redeploy.
#
# This file and panelalpha-install.php sit in the document root -- Group Office
# serves from www/ and `app_root: www` makes that /app -- but the generated
# vhost denies every path whose basename starts `panelalpha-`
# (resources/deploy/templates/apache-vhost.stub), so neither is reachable over
# HTTP.
set -e
cd /app

log() { echo "[groupoffice] $*"; }

# `check` prints one shell assignment and nothing else -- but the output is
# eval'd, so the filter is not a formality: anything else reaching stdout would
# be run by this shell. panelalpha-install.php puts all of PHP's own output on
# stderr for the same reason.
go_check() {
    php /app/panelalpha-install.php check | grep -E '^installed=[01]$'
}

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    log "no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# The bind mount from the compose override. Group Office keeps nothing
# user-generated in the checkout -- Blob::buildPath() puts every upload under
# file_storage_path -- which is the whole reason a redeploy is safe here, so a
# missing mount is a deploy that would quietly start writing files into a
# directory the next clone deletes.
if [ ! -d /data ]; then
    log "/data is not mounted; check overrides/docker-compose.override.yml" >&2
    exit 1
fi
mkdir -p /data/files /data/tmp
chmod 700 /data/files /data/tmp

# 1. www/config.php -- the database the engine provisioned and the paths the
#    mount provides. Written every time rather than only when missing: every
#    deploy re-clones over ~/project (#173), so there is never a file here to
#    preserve, and the values the container was handed are by definition the
#    ones that work. It holds getenv() calls rather than values, so it is not a
#    secret.
php /app/panelalpha-install.php config

# 2. The installation, or the upgrade.
#
#    There is a web installer here and this recipe denies it -- see
#    files/www/.htaccess -- so this is both the only way Group Office gets
#    installed and the reason the first-visitor-wins window never opens.
eval "$(go_check)"

if [ "${installed}" = "1" ]; then
    log "already installed; checking whether the schema matches the checkout"
    # Upstream's own Installer::upgrade(), and a no-op when the versions
    # already agree. It also drops the compiled client-script bundle, which
    # lives with the data rather than with the code and would otherwise be last
    # release's JavaScript against this release's PHP.
    php /app/panelalpha-install.php upgrade
    exit 0
fi

log "installing Group Office"
php /app/panelalpha-install.php install

eval "$(go_check)"
if [ "${installed}" != "1" ]; then
    log "the installer reported success but the schema or the administrator is not there" >&2
    exit 1
fi

log "installed; the administrator password is in ~/.panelalpha/groupoffice-admin-password"
