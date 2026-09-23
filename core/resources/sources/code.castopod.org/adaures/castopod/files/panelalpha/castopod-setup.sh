#!/bin/sh
# Runs inside the container on the install and upgrade stages, before Apache
# binds. Everything here is idempotent: the upgrade stage replays it on every
# redeploy, and a crash-looping container would replay it on every boot.
#
# It lives under panelalpha/ rather than at the top of the checkout because
# Castopod's document root is public/ -- nothing here is web-reachable.
set -e
cd /app

# The migrations, the seeds (roughly 1,900 category and language rows) and
# CodeIgniter's own bootstrap are the expensive steps, and the base image's
# compiled-in CLI memory_limit is not always enough. Far below the service's
# own cgroup limit, which the compose override raises for the same reason.
PHP='php -d memory_limit=512M'
DATA=/data

# ---------------------------------------------------------------------------
# 1. .env
#
# Castopod is configured entirely from this file: Config\App, Config\Database
# and Modules\Media\Config\Media are all CodeIgniter BaseConfig subclasses, and
# CodeIgniter's DotEnv is what overrides their properties from `app.baseURL`,
# `database.default.hostname` and the rest. Writing it here rather than in the
# prepare hook is what keeps the database password and the analytics salt out
# of the world-readable .env.default copy the engine takes of the checkout
# (engine#173) -- that copy is of the placeholder the hook left behind.
#
# Rewritten on every install and upgrade, deliberately: APP_URL and the
# database credentials are the deploy's to decide, and a redeploy that moved
# the account to a new domain has to be able to say so. The operator's own
# overrides come from the panel's env_vars, which the engine merges over the
# file the hook wrote, so they are read below and kept.

# APP_URL is the public https URL the engine gave this deploy. Castopod wants
# it with a trailing slash -- config('App')->baseURL is concatenated with route
# paths directly -- and generates every absolute URL in the RSS feed from it,
# which is what a podcast client follows to fetch the audio.
base_url="${APP_URL:-}"
if [ -z "${base_url}" ]; then
    echo '[castopod] APP_URL is not set; cannot work out this account public address' >&2
    exit 1
fi
case "${base_url}" in
    */) ;;
    *) base_url="${base_url}/" ;;
esac

if [ ! -f "${DATA}/analytics.salt" ]; then
    echo "[castopod] ${DATA}/analytics.salt is missing; did hooks/prepare.sh run?" >&2
    exit 1
fi
salt=$(cat "${DATA}/analytics.salt")

# The gateways are the URL prefixes the admin area and the auth routes live
# under (config('Admin')->gateway, config('Auth')->gateway). Kept at upstream's
# defaults, and written out rather than left to the defaults because
# InstallController requires them to be *present in .env* before it will move
# past its first screen -- and so an operator can change them here.
admin_gateway="${CP_ADMIN_GATEWAY:-cp-admin}"
auth_gateway="${CP_AUTH_GATEWAY:-cp-auth}"

# A temporary file and a rename, so a container that dies halfway through this
# never leaves Apache serving a half-written .env.
umask 077
cat > .env.panelalpha <<EOF
# Written by PanelAlpha on every deploy. Per-account values that must outlive a
# redeploy (the analytics salt, the instance owner's password) are in
# ~/.panelalpha/castopod, which is bind-mounted here at ${DATA}.
CI_ENVIRONMENT="production"

app.baseURL="${base_url}"
media.baseURL="${base_url}"
admin.gateway="${admin_gateway}"
auth.gateway="${auth_gateway}"
analytics.salt="${salt}"

database.default.hostname="${DB_HOST}"
database.default.port=${DB_PORT:-3306}
database.default.database="${DB_DATABASE}"
database.default.username="${DB_USERNAME}"
database.default.password="${DB_PASSWORD}"
database.default.DBPrefix="cp_"

cache.handler="file"
EOF
mv .env.panelalpha .env
chmod 600 .env
umask 022

# ---------------------------------------------------------------------------
# 2. The three PHP files Composer's own scripts write, which the deploy's
#    Composer run was not allowed to produce.
#
# PhpHostBuild::SAFE_INSTALL_FLAGS carries `--no-scripts` always -- a script is
# arbitrary PHP out of a customer repository and the install runs on the host
# daemon -- and the manifest's own follow-up step runs `composer run-script
# post-autoload-dump`, which this project does not define. Castopod puts its
# generators under `post-install-cmd` instead, so the deploy log says
#
#     Script "post-autoload-dump" is not defined in this package
#
# and moves on, leaving three classes that do not exist:
#
#   Opawg\UserAgentsV2Php\UserAgentsRSS   used by App\Controllers\FeedController
#                                         on its first line of work, so *the RSS
#                                         feed itself* is a fatal error.
#   Opawg\UserAgentsV2Php\UserAgents      Modules\Analytics' helper, on every
#                                         episode audio hit.
#   AdAures\Ipcat\IpDb                    the same helper, on every public
#                                         podcast page.
#
# Each generator downloads a dataset and prints a class to stdout, so a failed
# download writes an empty file that is worse than none -- `class not found`
# becomes `unexpected end of file`. Each one is therefore generated into a
# temporary file that is only moved into place if it parses, and a two-line
# stub of the same class is written when it does not: an account whose host
# cannot reach GitHub gets analytics that identify nothing rather than a site
# that does not answer.
generate() {
    target="$1"
    generator="$2"
    stub="$3"

    if [ -s "${target}" ]; then
        return 0
    fi
    if [ ! -f "${generator}" ]; then
        printf '%s' "${stub}" > "${target}"
        echo "[castopod] ${generator} is missing; wrote a stub for $(basename "${target}")"
        return 0
    fi

    if $PHP "${generator}" > "${target}.tmp" 2>/dev/null && [ -s "${target}.tmp" ] \
       && $PHP -l "${target}.tmp" >/dev/null 2>&1; then
        mv "${target}.tmp" "${target}"
        echo "[castopod] generated $(basename "${target}")"
    else
        rm -f "${target}.tmp"
        printf '%s' "${stub}" > "${target}"
        echo "[castopod] could not generate $(basename "${target}"); wrote a stub instead" >&2
    fi
}

UA_DIR=vendor/opawg/user-agents-v2-php/src
IPCAT_DIR=vendor/adaures/ipcat-php/src

generate "${UA_DIR}/UserAgents.php" "${UA_DIR}/UserAgentsGenerate.php" \
'<?php
namespace Opawg\UserAgentsV2Php;
/* PanelAlpha stub: the generator could not reach its dataset. */
class UserAgents {
    public static $devices = [];
    public static $db = [];
    public static function find($userAgent) { return null; }
}
'

generate "${UA_DIR}/UserAgentsRSS.php" "${UA_DIR}/UserAgentsRSSGenerate.php" \
'<?php
namespace Opawg\UserAgentsV2Php;
/* PanelAlpha stub: the generator could not reach its dataset. */
class UserAgentsRSS {
    public static $db = [];
    public static function find($userAgent) { return null; }
}
'

generate "${IPCAT_DIR}/IpDb.php" "${IPCAT_DIR}/IpDbGenerate.php" \
'<?php
namespace AdAures\Ipcat;
/* PanelAlpha stub: the generator could not reach its dataset. */
class IpDb {
    public static $db = [];
    public static function find($ipstr) { return null; }
}
'

# The persons taxonomy is a *language* file, generated from the podcast
# namespace taxonomy into a path .gitignore excludes
# (modules/Admin/Language/*/PersonsTaxonomy.php). A missing language file is
# not a missing class -- lang() returns the key -- but PersonModel::
# getTaxonomyOptions() then does `foreach ('PersonsTaxonomy.persons' as ...)`,
# which is a TypeError on the podcast and episode credit pages. Optional: it
# needs a second network round trip and nothing else depends on it.
TAXONOMY=vendor/adaures/podcast-persons-taxonomy/src
for locale in en fr; do
    out="modules/Admin/Language/${locale}/PersonsTaxonomy.php"
    if [ ! -s "${out}" ] && [ -f "${TAXONOMY}/TaxonomyGenerate.php" ]; then
        url="https://raw.githubusercontent.com/Podcastindex-org/podcast-namespace/main/taxonomy-${locale}.json"
        if $PHP "${TAXONOMY}/TaxonomyGenerate.php" "${url}" > "${out}.tmp" 2>/dev/null \
           && [ -s "${out}.tmp" ] && $PHP -l "${out}.tmp" >/dev/null 2>&1; then
            mv "${out}.tmp" "${out}"
        else
            rm -f "${out}.tmp"
            echo "[castopod] could not generate the ${locale} persons taxonomy" >&2
        fi
    fi
done

# ---------------------------------------------------------------------------
# 2b. The icon set, which is the fourth thing post-install-cmd generates and the
#     one that breaks every rendered page.
#
# `icon()` resolves an icon out of `PHPIcons\Icons::DATA`, and that class is
# *written* by `php-icons init` (empty) and filled by `php-icons scan`, which
# walks app/, themes/ and resources/ for icon names and pulls each one from the
# local sets in resources/icons or from the iconify API. Neither has run, so
# vendor/yassinedoghri/php-icons/src/Icons.php does not exist and the first
# icon on a page is
#
#     Error: Class "PHPIcons\Icons" not found
#       vendor/yassinedoghri/php-icons/src/PHPIcons.php:62
#       themes/cp_app/home.php(32)
#
# -- so the home page, the admin area and the login form are all HTTP 500 while
# /health answers 200, because /health renders no view. Measured: 385 files
# scanned, 161 icons, about a second.
#
# It has to run on every deploy and not just the first: the file is inside
# vendor/, which the build rewrites from scratch.
#
# `init` first, because `scan` needs the class to exist before it can replace
# it, and `init` is local and cannot fail. `scan` is allowed to fail -- it is
# the half that needs the iconify API -- and what is left then is an Icons class
# with no data, which PHPIcons renders as its configured placeholder. A site
# with placeholder glyphs beats a site that answers 500.
ICONS=vendor/yassinedoghri/php-icons/src/Icons.php
if [ -f vendor/bin/php-icons ]; then
    $PHP vendor/bin/php-icons init >/dev/null 2>&1 || true
    if ! $PHP vendor/bin/php-icons scan >/dev/null 2>&1; then
        echo '[castopod] php-icons scan failed; icons will render as placeholders' >&2
    fi
    # A scan that died midway can leave the file unparseable, which is worse
    # than the empty class `init` writes.
    if [ -f "${ICONS}" ] && ! $PHP -l "${ICONS}" >/dev/null 2>&1; then
        rm -f "${ICONS}"
        $PHP vendor/bin/php-icons init >/dev/null 2>&1 || true
    fi
fi
if [ ! -f "${ICONS}" ]; then
    echo '[castopod] no PHPIcons\Icons class; every page that renders an icon will be a 500' >&2
fi

# ---------------------------------------------------------------------------
# 3. The schema, and the rows Castopod cannot run without.
#
# `install:init-database` is Castopod's own command and is exactly what the
# wizard's index() does once it has a database: every migration, then
# AppSeeder, which is the podcast category and language tables. Both halves are
# idempotent -- migrations are tracked in the migrations table and the two
# seeders insert with `->ignore(true)` -- so this is also the upgrade stage's
# schema update, which is what upstream's container runs on every boot.
$PHP spark install:init-database

# ---------------------------------------------------------------------------
# 4. The instance owner, on the first deploy only.
#
# Until one exists, Modules\Install\Controllers\InstallController::index()
# renders the create-superadmin form to anyone who asks for /cp-install and
# createSuperAdminAction() makes that visitor the owner. There is no
# authentication in front of either. Creating the owner here, before Apache
# binds, is what closes that: the same controller then throws
# PageNotFoundException and /cp-install answers 404.
#
# `install:create-superadmin` takes the username and the email as options and
# asks for the password twice on a hidden prompt, so the generated one goes in
# on stdin. It refuses outright when an owner already exists, which is what
# makes replaying this on the upgrade stage a no-op.
if [ ! -f "${DATA}/admin-credentials" ]; then
    echo "[castopod] ${DATA}/admin-credentials is missing; refusing to serve an open /cp-install" >&2
    exit 1
fi
# shellcheck disable=SC1090
. "${DATA}/admin-credentials"
admin_user="${CASTOPOD_ADMIN_USERNAME:-admin}"

# The address has to be one CodeIgniter's `valid_email` accepts, and that rule
# is filter_var(FILTER_VALIDATE_EMAIL), which wants a dot in the domain --
# `admin@localhost` is rejected and the command aborts with `Super admin
# creation aborted`, leaving /cp-install open on a public address. Measured on
# the deploy before this line existed. So the account's own hostname is used,
# which is a real name that resolves, and it is written back into the
# credentials file once the user exists so that a later redeploy onto a
# different domain still reports the address the owner actually signs in with.
admin_email="${CASTOPOD_ADMIN_EMAIL:-}"
if [ -z "${admin_email}" ]; then
    admin_host=$(printf '%s' "${base_url}" | sed -e 's|^[a-z][a-z0-9+.-]*://||' -e 's|[:/].*$||')
    admin_email="admin@${admin_host}"
fi

# Captured rather than discarded: the two outcomes that are fine are "created"
# and "already created", and everything else is a hole in the account's front
# door. `install:create-superadmin` asks for the password twice on a prompt
# that reads stdin, so the generated one goes in on a pipe.
creation=$(printf '%s\n%s\n' "${CASTOPOD_ADMIN_PASSWORD:-}" "${CASTOPOD_ADMIN_PASSWORD:-}" \
    | $PHP spark install:create-superadmin -n "${admin_user}" -e "${admin_email}" 2>&1 || true)

# `already created` is matched first and that order is load-bearing: the two
# messages are `Super admin was already created!` and `Super admin "admin"
# created`, and the first contains the second's words, so a pattern for the
# success case put above it would swallow the no-op case as well.
case "${creation}" in
    *'already created'*)
        echo "[castopod] the instance owner already exists; leaving it alone"
        ;;
    *'created'*)
        if ! grep -q '^CASTOPOD_ADMIN_EMAIL=' "${DATA}/admin-credentials"; then
            printf 'CASTOPOD_ADMIN_EMAIL=%s\n' "${admin_email}" >> "${DATA}/admin-credentials"
        fi
        echo "[castopod] created the instance owner '${admin_user}' <${admin_email}>"
        ;;
    *)
        # Failing the boot is the safe outcome. The entrypoint execs Apache as
        # its last line, so exiting here means nothing is served at all -- and
        # a deploy that serves nothing is better than one that serves the
        # install wizard to whoever finds the address first.
        echo '[castopod] could not create the instance owner, so /cp-install would be open to the first visitor:' >&2
        printf '%s\n' "${creation}" >&2
        exit 1
        ;;
esac

# ---------------------------------------------------------------------------
# 5. Clear the cache while nothing is being served.
#
# The file cache lives in writable/cache and survives a redeploy only by
# accident -- the checkout is re-cloned -- but the settings and taxonomy
# entries it holds are keyed on data that has just changed underneath it, and
# on the upgrade stage that is the difference between a podcast page rendering
# and rendering last deploy's assets.
$PHP spark cache:clear >/dev/null 2>&1 || true

echo '[castopod] setup finished'
