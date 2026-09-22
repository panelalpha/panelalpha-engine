#!/bin/sh
# Runs inside the container on the install and upgrade stages, before the
# healthcheck can pass. Everything here is idempotent: the upgrade stage
# replays it on every redeploy, over an account that already has data.
#
# `stage: build` is not an option -- those commands are inert (engine defect
# #171) -- and would be wrong anyway: there is no image build for a bind-mounted
# PHP app, and none of this is knowable before the database container is up.
set -e
cd /app

# The secrets directory, bind-mounted read-only from ~/.panelalpha/phorge by
# overrides/docker-compose.override.yml. Outside ~/project because ~/project is
# wiped and re-cloned on every deploy (engine defect #173) while the database
# volume is not.
if [ ! -r /panelalpha/db.env ]; then
    echo "[phorge] /panelalpha/db.env is not readable; is the bind mount in" >&2
    echo "         overrides/docker-compose.override.yml still there?" >&2
    exit 1
fi
. /panelalpha/db.env
ADMIN_PASSWORD_FILE=/panelalpha/admin-password

if [ -z "${PHORGE_DB_PASSWORD:-}" ]; then
    echo "[phorge] PHORGE_DB_PASSWORD is empty in /panelalpha/db.env" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 1. Arcanist has to be where Phorge looks, and the failure is otherwise a
#    plain "FATAL ERROR" with no context in the deploy log.
if [ ! -f /arcanist/src/init/init-library.php ]; then
    echo "[phorge] /arcanist is missing. Phorge loads its library from" >&2
    echo "         dirname(/app) -- see hooks/prepare.sh and the volume in" >&2
    echo "         overrides/docker-compose.override.yml." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 1b. The database's credentials, which are the engine's until this runs.
#
#     The harvested sidecar is handed `root`/`app` and an `app`/`app` user by
#     the engine, with MYSQL_ROOT_HOST '%'. This replaces both with a password
#     generated for this account. It has to happen before the configuration
#     below, because that is where the password is written.
php panelalpha/phorge-db-secure.php

# ---------------------------------------------------------------------------
# 2. Configuration, into conf/local/local.json.
#
#    Rewritten on every deploy rather than only the first, because the clone
#    wipes conf/local/ along with the rest of ~/project (engine defect #173).
#    An account whose database is full of work would otherwise come back with
#    no idea where its database is.
#
#    `bin/config set` rather than a hand-written local.json: it validates each
#    key against the option definitions, so a typo is an error here instead of
#    a mystery at runtime, and it is what upstream's installation guide has the
#    administrator run.
#
#    APP_URL is the account's own https:// address, set by the engine.
if [ -z "${APP_URL:-}" ]; then
    echo "[phorge] APP_URL is not set; cannot configure phabricator.base-uri" >&2
    exit 1
fi

# `db` is the service name in the compose project, and MYSQL_HOST is what the
# engine sets to it. Phorge connects as root because it creates and alters 54
# schemas of its own -- see the README and phorge-db-secure.php.
./bin/config set mysql.host "${MYSQL_HOST:-db}"
./bin/config set mysql.port "${MYSQL_PORT:-3306}"
./bin/config set mysql.user root
./bin/config set mysql.pass "$PHORGE_DB_PASSWORD"

# Trailing slash: Phorge normalises either form, and the guide writes it this
# way. The engine's proxy terminates TLS and forwards plain HTTP; the base
# image's auto_prepend_file restores $_SERVER['HTTPS'] from the forwarded
# headers, which is what makes an https:// base URI correct rather than a
# redirect loop.
./bin/config set phabricator.base-uri "$APP_URL"

# The engine's health probe, and the reason a deploy that works is otherwise
# reported as `serving-error_page`.
#
# Phorge picks a "site" by matching the request's Host against
# `phabricator.base-uri`, `phabricator.production-uri` and this list
# (PhabricatorPlatformSite::newSiteForRequest); a request that matches none is
# answered with a 500 "Site Not Found" page
# (AphrontApplicationConfiguration::buildSiteForRequest, line 545). AppHealth
# probes `http://127.0.0.1:8000/`, whose Host is `127.0.0.1:8000` and matches
# nothing -- so a completely healthy install scored `serving-error_page` with
# an HTTP 500 on port 8000 while the public domain served the login page.
# (Measured: that was this recipe's verdict before this line.)
#
# Only the Host is compared -- AphrontSite::isHostMatch takes getDomain() of
# each URI and AphrontRequest::getHost() strips the port -- so the port and
# scheme written here are documentation. Nothing outside the container can
# send this Host: it is the loopback address of the app container itself.
# Generated links still come from base-uri; the option only decides which
# requests are served at all.
./bin/config set phabricator.allowed-uris '["http://127.0.0.1:8000/"]'

# Read by Phorge for every rendered timestamp. PHP's own date.timezone is set
# in panelalpha/php/zz-phorge.ini; this is the application-level one that
# PhabricatorTimezoneSetupCheck looks at.
./bin/config set phabricator.timezone UTC

# Where uploaded files and cloned repositories go. Both default to paths under
# /var/tmp that this container's uid cannot create; both are inside /app, which
# is the account's own bind-mounted directory, and both are outside the
# document root (webroot/). The dot prefix means that even if the document root
# ever moved to /app, the generated vhost's `<DirectoryMatch "/\.">` denies
# them.
./bin/config set storage.local-disk.path /app/.phorge-files
./bin/config set repository.default-local-path /app/.phorge-repos

# The daemons are not running (see the README), so nothing drains the outbound
# mail queue. The `test` adapter discards messages instead, which is the honest
# behaviour: without a mailer configured at all, every notification would sit
# in the queue forever and PhabricatorMailSetupCheck would raise an issue about
# it on every page.
#
# `cluster.mailers`, not `metamta.mail-adapter`. The latter is what every older
# Phabricator guide says and Phorge no longer has it: `bin/config set` answers
# `Configuration key "metamta.mail-adapter" is unknown` and, with `set -e`,
# takes the deploy down with it. (Measured.) It was replaced by a list of
# keyed mailers -- PhabricatorMetaMTAMail::newMailers(), line 572 -- and the
# adapter's own type key is `test`
# (PhabricatorMailTestAdapter::ADAPTERTYPE).
./bin/config set cluster.mailers '[{"key": "discard", "type": "test"}]'

# It holds the MySQL root password. `bin/config` creates the file 644.
chmod 600 conf/local/local.json

# ---------------------------------------------------------------------------
# 3. The schemas.
#
#    This is the step the engine's own `database: mysql` cannot serve:
#    `bin/storage upgrade` issues `CREATE DATABASE {namespace}_<app>` 54 times
#    (resources/sql/quickstart.sql) and then applies ~900 patches across them.
#    --force is what makes it non-interactive; without it the workflow prompts
#    "Are you completely sure you really want to apply these patches?" and the
#    deploy hangs until its timeout.
#
#    Idempotent by design: the applied patches are recorded in
#    {namespace}_meta_data.patch_status, and a second run applies only what is
#    new. That is what makes this safe on the upgrade stage, where the account
#    already has data.
echo "[phorge] applying storage patches (54 schemas)" >&2
./bin/storage upgrade --force

# ---------------------------------------------------------------------------
# 4. The first administrator, and closing the door that would otherwise let a
#    stranger be one. See panelalpha/phorge-bootstrap.php.
php panelalpha/phorge-bootstrap.php "$ADMIN_PASSWORD_FILE"

# ---------------------------------------------------------------------------
# 5. Caches that are keyed on the code, not the data.
#
#    The Celerity resource map and the remarkup render cache are derived from
#    the checkout, and the checkout is replaced wholesale on every deploy
#    (engine defect #173). The workflow takes only --all or --caches
#    (PhabricatorCacheManagementPurgeWorkflow, lines 13-17), so --all it is; on
#    a fresh install there is nothing in them to lose and on a redeploy the
#    cost is one cold page.
#
#    `|| true`: a purge that fails is a slow first request, not a broken
#    install, and it must not fail the deploy after the schema is upgraded.
./bin/cache purge --all || true

echo "[phorge] setup complete" >&2
