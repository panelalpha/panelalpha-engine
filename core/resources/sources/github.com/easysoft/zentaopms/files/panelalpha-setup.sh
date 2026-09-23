#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Idempotent: the upgrade stage replays it on every redeploy.
#
# This file and panelalpha-install.php sit at the repository root, which is
# *not* the document root -- ZenTao serves from www/ -- so neither is reachable
# over HTTP. That is why they are not in a panelalpha/ subdirectory and do not
# need the vhost's `panelalpha-*` deny rule to save them, though it would.
set -e
cd /app

log() { echo "[zentao] $*"; }

# `check` and `version` print shell assignments and nothing else -- but the
# output is eval'd, so the filter is not a formality: anything else reaching
# stdout would be run by this shell. panelalpha-install.php puts all of PHP's
# own output on stderr for the same reason.
zt_check() {
    php /app/panelalpha-install.php check | grep -E '^installed=[01]$'
}

zt_version() {
    php /app/panelalpha-install.php version | grep -E '^(schema|code)=' | sed 's/^/zt_/'
}

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ]; then
    log "no DB_* in the environment; is 'database: mysql' still in panelalpha.yaml?" >&2
    exit 1
fi

# 1. config/my.php -- the database the engine provisioned and the account's own
#    URL. Written every time rather than only when missing: every deploy
#    re-clones over ~/project (#173), so there is never a file here to preserve,
#    and the values the container was handed are by definition the ones that
#    work. It holds getenv() calls rather than values, so it is not a secret.
php /app/panelalpha-install.php config

# 2. The installation itself.
#
#    There is no web installer to race here -- .gitignore keeps www/install.php
#    out of the repository and only the Makefile's release build creates it --
#    so this is not closing a window, it is the only way ZenTao gets installed
#    at all. www/index.php redirects to install.php when the application is not
#    installed, so without this every request would be a 404.
eval "$(zt_check)"

if [ "${installed}" = "1" ]; then
    log "already installed; leaving the database alone"

    # 3. A version bump in the checkout.
    #
    #    ZenTao migrates through module/upgrade, an interactive multi-step
    #    wizard keyed on the version it is upgrading *from*, and www/upgrade.php
    #    -- the entry point that drives it -- is .gitignored too, so a clone has
    #    no way to run it and index.php would redirect every visitor to a 404.
    #    Driving that wizard from the CLI is not something this recipe does yet,
    #    so the honest thing is to say so loudly rather than to serve a broken
    #    application quietly. The deploy is failed: the previous container keeps
    #    running, and the account's data is untouched.
    eval "$(zt_version)"
    if [ "${zt_schema}" != "${zt_code}" ]; then
        log "the checkout is ZenTao ${zt_code} but this account's schema is ${zt_schema}." >&2
        log "ZenTao's upgrade path is not automated in this recipe; refusing to serve a" >&2
        log "checkout whose code and schema disagree. Pin the repository to ${zt_schema}" >&2
        log "or migrate the database by hand." >&2
        exit 1
    fi
    log "schema ${zt_schema} matches the checkout"
    exit 0
fi

log "installing ZenTao for super-admin '${ZENTAO_ADMIN_USER:-admin}'"

# Two processes, and not for tidiness. The schema is built by a process that is
# deliberately *not* "installed" -- the router reads ZenTao's settings out of
# zt_config the moment that flag is true, and zt_config is what the first phase
# creates -- while the second needs the opposite, because router::connectDB()
# refuses to build a DAO while `installed` is false. ZenTao's own web installer
# has the same split across its ajaxCreateTable and step5 requests.
php /app/panelalpha-install.php schema
php /app/panelalpha-install.php seed

eval "$(zt_check)"
if [ "${installed}" != "1" ]; then
    log "the installer reported success but the schema or the admin is not there" >&2
    exit 1
fi

log "installed; the super-admin password is in ~/.panelalpha/zentao-admin-password"
