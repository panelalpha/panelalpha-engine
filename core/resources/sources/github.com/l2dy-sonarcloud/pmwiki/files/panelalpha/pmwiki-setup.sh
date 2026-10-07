#!/bin/sh
# PanelAlpha setup for PmWiki (git mirror github.com/l2dy-sonarcloud/pmwiki of
# the SVN-only canonical svn://pmwiki.org/pmwiki).
#
# Runs before the web server on every boot (start stage, before: true). It keeps
# the page store and attachments outside the per-deploy wipe of ~/project, and
# writes the admin password hash so the wiki is not left world-editable. The
# password is the engine's (`credentials:` in panelalpha.yaml). Idempotent: the
# hash is rewritten only when it does not match that password.
#
# Persistence lives on /pa-data, which the recipe's docker-compose.override.yml
# bind-mounts from ~/.panelalpha (the account home survives a redeploy; ~/project
# does not). HOME is not set in the app container, so absolute
# paths only.
set -eu

PA_DATA="/pa-data/pmwiki"
HASH="${PA_DATA}/admin.hash"

if [ ! -d /pa-data ]; then
    echo "panelalpha/pmwiki: /pa-data is not mounted; the compose override did not apply" >&2
    exit 1
fi

umask 077
mkdir -p "${PA_DATA}" "${PA_DATA}/wiki.d" "${PA_DATA}/uploads"
chmod 700 "${PA_DATA}"

# Defence in depth for attachments: even though PmWiki's $UploadExts whitelist
# excludes executable types, make sure nothing under uploads/ is ever run as PHP.
if [ ! -f "${PA_DATA}/uploads/.htaccess" ]; then
    cat > "${PA_DATA}/uploads/.htaccess" <<'HT'
# Attachments are static downloads only -- never execute anything here.
php_flag engine off
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar
<FilesMatch "(?i)\.(php[0-9]?|phtml|phar)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Deny from all
  </IfModule>
</FilesMatch>
HT
fi

# Move the page store and attachments out of the checkout and link them back.
# PmWiki writes pages to wiki.d/ and reads attachments from uploads/ relative to
# the app root; following these symlinks lands both on persistent storage.
rm -rf /app/wiki.d /app/uploads
ln -sfn "${PA_DATA}/wiki.d"  /app/wiki.d
ln -sfn "${PA_DATA}/uploads" /app/uploads

# Never serve the clone's VCS metadata.
rm -rf /app/.git

# The admin password is the engine's, in ~/.panelalpha/app-credentials.env
# (/pa-data here). Its bcrypt hash goes to admin.hash (read by local/config.php);
# PmWiki keeps passwords in config only, so the engine's value is authoritative
# and the hash is rewritten whenever it does not match it.
. /pa-data/app-credentials.env
if ! PW="${PMWIKI_ADMIN_PASSWORD}" H="${HASH}" php -r 'exit(is_file(getenv("H")) && password_verify(getenv("PW"), trim(file_get_contents(getenv("H")))) ? 0 : 1);'; then
    PW="${PMWIKI_ADMIN_PASSWORD}" php -r 'echo password_hash(getenv("PW"), PASSWORD_DEFAULT);' > "${HASH}"
    chmod 600 "${HASH}"
    echo "panelalpha/pmwiki: admin password hash written" >&2
fi
