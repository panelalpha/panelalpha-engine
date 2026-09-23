#!/bin/bash
# Account shell, after the clone and before the build.
#
# Nothing here can know the database credentials -- the engine provisions those
# while it writes the compose file, which is after this hook -- so everything
# that needs them is in files/panelalpha-setup.sh, inside the container. What
# is left is the two things that must exist *before* the container is created:
# the directory the compose file bind-mounts, and the admin password, because
# `env_file:` is read at container creation and a value written afterwards
# never reaches the process.
set -e
cd ~/project

# Everything this account accumulates that has to outlive a redeploy lives
# here: the settings file with $wgSecretKey in it, every uploaded file, the
# localisation cache and the php.ini.
#
# A redeploy empties and re-clones ~/project before it does anything else
# (System/Project/Dind/Source/GitRepository.php:89 calls
# `tree()->clearContents($gitProjectDir)`, engine#173). ~ itself is root-owned
# 0755, so an account cannot create anything directly in its own home;
# ~/.panelalpha is created with the account and belongs to it, which is why
# the data directory is a child of that one. Created here, before the mount is
# made, so Docker never gets to create it as root -- a bind-mount source that
# does not exist is created by the daemon, and the container then runs as this
# account and cannot write it.
DATA_HOME="${HOME}/.panelalpha/mediawiki"
mkdir -p "${DATA_HOME}/images" "${DATA_HOME}/cache" "${DATA_HOME}/php"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# 1. The first bureaucrat's password.
#
#    MediaWiki creates no user of its own, and `mw-config/` will create one for
#    anybody who asks: mw-config/index.php defines MW_CONFIG_CALLBACK before
#    WebStart so LocalSettings.php is never loaded, and WebInstaller's page
#    sequence runs Language, ExistingWiki, Welcome, DBConnect... with
#    WebInstallerExistingWiki::execute() -- the only gate -- returning 'skip'
#    the moment Installer::getExistingLocalSettings() finds nothing. So the
#    installer is run at deploy time instead, and it needs a password that
#    exists before it does.
#
#    Outside ~/project, because a redeploy deletes that directory while the
#    user row in the account's MySQL database survives: a password kept beside
#    the code would stop matching the account it was created for. 0600 inside
#    the 0700 directory above.
#
#    Written once and never rewritten. The setup script is a no-op on an
#    account that already has a wiki, so a second password here would be a
#    password for nothing.
CREDENTIALS="${DATA_HOME}/admin-credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    # The umask is inside a subshell on purpose: it has to cover the
    # redirection that creates the file, and it must not leak into the rest of
    # this script, where a 077 default would leave directories the engine
    # (www-data) cannot scan when it walks the tree for the document root.
    #
    # The alphabet is deliberately alphanumeric. MediaWiki's own
    # UserPasswordPolicy runs against this value in CliInstaller
    # (includes/Installer/CliInstaller.php:107) and rejects a weak one before
    # it touches the database; 32 characters of [A-Za-z0-9] passes every check
    # in the bureaucrat/sysop/interface-admin policy and survives being
    # retyped, which base64 padding does not.
    ( umask 077; cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on the first deploy. This is the wiki's first
# bureaucrat -- sign in at https://<your-domain>/index.php/Special:UserLogin .
#
# MediaWiki's web installer at /mw-config/ is first-visitor-wins: it creates
# the wiki and its administrator and asks nobody who they are. It was run from
# the deploy instead, with these values, and /mw-config/ is now denied by the
# web server (files/mw-config/.htaccess), which is what upstream's INSTALL file
# tells an administrator to do by hand once they are finished.
#
# Change the password under Special:ChangePassword and this file stops being
# interesting.
MEDIAWIKI_ADMIN_USERNAME=Admin
MEDIAWIKI_ADMIN_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)
EOF
    )
    chmod 600 "${CREDENTIALS}"
    echo "[mediawiki] generated the first bureaucrat's password in ${CREDENTIALS}"
fi

# It is *not* handed to the container as an environment variable, and that is
# a decision rather than an omission. A second `env_file:` is the usual way to
# get a secret past a redeploy without putting it in ~/project, but this
# recipe already bind-mounts ~/.panelalpha/mediawiki at /data for the settings
# file and the uploads, so the setup script can read the password out of a
# 0600 file it can already see. An environment variable would additionally put
# it in `printenv`, in `docker inspect`, and in the process environment of
# every PHP request the wiki serves, for a value that is used exactly once on
# the first deploy.

# ---------------------------------------------------------------------------
# 2. Uploads, out of the directory the next deploy deletes.
#
#    $wgUploadDirectory defaults to MW_INSTALL_PATH/images and $wgUploadPath to
#    $wgScriptPath/images, so where MediaWiki writes a file and the URL a
#    browser fetches it from are the same path -- and MediaWiki serves from its
#    own root, so that path is inside the checkout. Moving the directory with
#    $wgUploadDirectory alone would leave $wgUploadPath pointing at a
#    directory Apache no longer has.
#
#    A symlink keeps both: the path and the URL stay `images/...`, and the
#    bytes live on the bind mount. Absolute and pointing at the mount point,
#    because it is resolved inside the container where the override file
#    mounts ~/.panelalpha/mediawiki at /data. The generated vhost sets
#    `Options -Indexes +FollowSymLinks` on the document root, which is what
#    makes Apache follow it.
#
#    upstream's own images/.htaccess is copied across the first time, and it
#    is not decoration: `php_flag engine off` is what stops an uploaded file
#    ever being executed, and the Content-Security-Policy header with
#    `sandbox` is what stops an uploaded SVG or HTML file running script in
#    the wiki's origin. Copying rather than regenerating means a new upstream
#    rule arrives with the release that adds it.
if [ -d images ] && [ ! -L images ]; then
    cp -rn images/. "${DATA_HOME}/images/" 2>/dev/null || true
    rm -rf images
fi
chmod -R u+rwX "${DATA_HOME}/images"
ln -sfn /data/images images

# ---------------------------------------------------------------------------
# 3. A php.ini, on a platform that loads none.
#
#    `php --ini` in the base image answers "Loaded Configuration File: (none)"
#    (engine#185), so what is in force is PHP's compiled-in defaults. Two of
#    them matter to a wiki: upload_max_filesize = 2M, and memory_limit = 128M,
#    which `maintenance/run.php update` and the localisation cache do not fit
#    in. The file's own comments say the rest.
#
#    It goes on the bind mount rather than in the checkout because the document
#    root here *is* the checkout, and the vhost's `^panelalpha[-.]` denial
#    matches a file name rather than a path component -- a `panelalpha-php/`
#    directory at the top would be inside the served tree. /data is not served
#    at all.
cat > "${DATA_HOME}/php/zz-mediawiki.ini" <<'EOF'
; Written by PanelAlpha for MediaWiki. Reached through PHP_INI_SCAN_DIR, which
; the compose override points at this directory as well as the image's own.

; MediaWiki's installer, maintenance/run.php update and the localisation cache
; rebuild all exceed the compiled-in 128M. Measured: `update.php` on a fresh
; schema peaks around 90M, the parser cache warm-up on a large page more.
memory_limit = 256M

; An uploaded file on a wiki is a scanned page or a photograph. The compiled-in
; defaults are 2M and 8M, and a POST over post_max_size is discarded before PHP
; is entered -- $_POST and $_FILES both arrive empty, so Special:Upload would
; report a request that carried a file as one that did not.
upload_max_filesize = 64M
post_max_size = 72M
max_file_uploads = 20

; A large upload or an import is not a fast request.
max_execution_time = 180

; Never to a browser. MediaWiki renders its own exception pages and
; $wgShowExceptionDetails governs how much they say; PHP printing a stack
; trace over the top of that would leak paths and, in a database error, a DSN.
display_errors = Off
log_errors = On
error_log = /dev/stderr

; MediaWiki sets its own session parameters, but the cookie flags are a
; property of the deployment: the account is served over https by the proxy.
session.cookie_httponly = 1
session.use_strict_mode = 1
EOF
chmod 644 "${DATA_HOME}/php/zz-mediawiki.ini"

# ---------------------------------------------------------------------------
# 4. cache/ -- not the localisation cache, the one thing upstream puts here.
#
#    The repository ships cache/.htaccess (`Require all denied`) and nothing
#    else; MediaWiki-Docker puts its SQLite database and its logs in it. This
#    recipe puts the localisation cache on the bind mount instead
#    ($wgCacheDirectory = /data/cache, set in the generated settings file), so
#    a redeploy does not throw away a cache it would spend a minute rebuilding.
#    The directory in the checkout is left exactly as upstream ships it.

echo "[mediawiki] prepared: images/ on ${DATA_HOME}/images, php.ini installed, admin password ready"
