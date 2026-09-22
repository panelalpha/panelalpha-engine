#!/bin/bash
# Account shell, after the clone and before the build. Nothing here can know
# the database credentials -- the engine provisions those while it writes the
# compose file, after this hook -- so db.ini is written by
# files/panelalpha-setup.sh instead, inside the container.
set -e
cd ~/project

# Everything this account accumulates that must outlive a redeploy lives here.
# A redeploy empties and re-clones ~/project (engine#173:
# System/Project/Dind/Source/GitRepository.php:90 calls
# `tree()->clearContents($gitProjectDir)` before `clone`), so an Omeka site
# that kept its uploads under the checkout would lose every file on the next
# deploy while the `files` rows pointing at them survived. ~ itself is
# root-owned 0755 and nothing can be created in it; ~/.panelalpha is created
# with the account and belongs to it.
DATA_HOME="${HOME}/.panelalpha/omeka"
mkdir -p "${DATA_HOME}/files" "${DATA_HOME}/php"
chmod 700 "${DATA_HOME}"

# ---------------------------------------------------------------------------
# 1. The front controller's rewrite rules.
#
#    Omeka Classic serves from its own root and .gitignore lists `/.htaccess`:
#    the repository ships `.htaccess.changeme` and expects the operator to
#    rename it. Without it every route but `/` is a 404 from Apache -- the
#    three RewriteRules at the bottom of that file are what send `/items/1`
#    to index.php, `/admin/...` to admin/index.php and `/install/...` to
#    install/install.php -- and `GET /db.ini` returns the account's MySQL
#    password in plain text, because the only thing that denies it is the
#    `<FilesMatch "\.ini$">` block inside this same file.
#    Installer_Requirements::_checkHtaccessFilesExist() fails the install
#    outright when it is missing, which is upstream saying the same thing.
#
#    Copied rather than symlinked so an operator can edit it, and never
#    overwritten: a site that has been tuned keeps its rules.
if [ ! -f .htaccess ] && [ -f .htaccess.changeme ]; then
    cp .htaccess.changeme .htaccess

    # Two files the engine puts in this directory that upstream could not have
    # known about, and the document root here is the repository root.
    #
    # `docker-compose.override.yml` is the recipe's own overlay. The generated
    # vhost denies `^(?:docker-compose\.ya?ml|panelalpha[-.])`, and that
    # pattern does not match it -- `docker-compose.override.yml` has
    # `override` where the regex wants `yml` (engine#181). It names the base
    # image, the account uid, the bind mounts and every environment variable
    # the project was given.
    #
    # `*.log` is application/logs/errors.log. Omeka's logger is off by default
    # (config.ini `log.errors`), but the moment an operator turns it on that
    # file holds SQL errors and stack traces, and .htaccess's rewrite serves
    # any existing non-PHP file straight from disk. Measured before this
    # block existed: `GET /application/logs/errors.log.empty` answered 200.
    cat >> .htaccess <<'EOF'

# ------------------------------------ #
# Added by PanelAlpha on first deploy.  #
# ------------------------------------ #

# The engine's own files live in this directory, and neither is ever a page.
<FilesMatch "^docker-compose\.">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>

# application/logs/errors.log, wherever a plugin puts one too.
<FilesMatch "\.log$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>
EOF
    echo "[omeka] installed .htaccess from .htaccess.changeme"
fi

# ---------------------------------------------------------------------------
# 2. application/config/config.ini, which Omeka refuses to boot without.
#
#    Omeka_Application_Resource_Config::init() throws
#    `Your Omeka configuration file is missing.` when CONFIG_DIR/config.ini
#    does not exist, and `.gitignore` lists `/application/config/config.ini`:
#    the repository ships config.ini.changeme. This is the second of the two
#    missing files behind the HTTP 500 on every path (db.ini is the first and
#    the one that throws earliest).
#
#    Copied from upstream's own file rather than shipped as a recipe snippet,
#    so a new upstream setting arrives with the release that introduces it.
#    One line is added.
#
#    **The derivative strategy is the line.** Omeka's default is
#    Omeka_File_Derivative_Strategy_ExternalImageMagick, which shells out to
#    ImageMagick's `convert`; the shared PHP base image ships the `imagick`
#    PHP extension and has no ImageMagick CLI at all (`which convert magick
#    identify` finds nothing). Left alone, every uploaded image would fail its
#    thumbnail -- at upload time, long after the deploy said it was fine. The
#    Imagick strategy is Omeka's own implementation over the extension that is
#    actually there. `[site]` is the file's only section, so appending puts
#    this inside it.
if [ ! -f application/config/config.ini ] && [ -f application/config/config.ini.changeme ]; then
    cp application/config/config.ini.changeme application/config/config.ini
    cat >> application/config/config.ini <<'EOF'

;;;;;;;;;;;;;;;;;;;;;;;;
; Added by PanelAlpha  ;
;;;;;;;;;;;;;;;;;;;;;;;;

; The base image has ext/imagick and no `convert` binary, so the stock
; ExternalImageMagick strategy would fail on every uploaded image.
fileDerivatives.strategy = "Omeka_File_Derivative_Strategy_Imagick"
EOF
    echo "[omeka] installed application/config/config.ini from config.ini.changeme"
fi

# ---------------------------------------------------------------------------
# 3. Uploaded files, out of the directory the next deploy deletes.
#
#    bootstrap.php hard-codes `FILES_DIR = BASE_DIR . '/files'` and
#    `WEB_FILES = WEB_ROOT . '/files'`, and Omeka_Storage_Adapter_Filesystem
#    writes originals and the three derivative sizes under it. There is no
#    setting that moves it: `storage.adapterOptions.localDir` exists, but the
#    *URL* a browser fetches an image from is built from WEB_FILES, which is
#    the document root plus `/files`, so moving the directory out of the
#    document root would break every image on the site.
#
#    A symlink is the arrangement that keeps both: the path Omeka writes to
#    and the URL a visitor fetches stay `files/...`, and the bytes live on the
#    bind mount. Absolute, pointing at the mount point rather than at
#    ~/.panelalpha: it is resolved inside the container, where the override
#    file mounts ~/.panelalpha/omeka at /data. The generated vhost sets
#    `Options -Indexes +FollowSymLinks` on the document root, which is what
#    makes Apache follow it.
#
#    The repository's own files/ holds five directories each containing an
#    index.html stub; they are copied across once so a fresh account starts
#    with the shape Omeka expects, and never afterwards.
if [ -d files ] && [ ! -L files ]; then
    cp -rn files/. "${DATA_HOME}/files/" 2>/dev/null || true
    rm -rf files
fi
mkdir -p "${DATA_HOME}"/files/original "${DATA_HOME}"/files/fullsize \
         "${DATA_HOME}"/files/thumbnails "${DATA_HOME}"/files/square_thumbnails \
         "${DATA_HOME}"/files/theme_uploads
chmod -R u+rwX "${DATA_HOME}/files"
ln -sfn /data/files files

# ---------------------------------------------------------------------------
# 4. The first administrator's password, generated per account and never a
#    default.
#
#    Omeka Classic creates no user of its own. install/install.php puts a form
#    at /install that creates the super user, and nothing authenticates it:
#    IndexController::preDispatch() only checks whether the `options` table
#    exists, so on an account that has just been given a public HTTPS name,
#    the first stranger to load /install becomes the site's super user. The
#    install runs from the install stage instead (files/panelalpha-setup.sh),
#    and it needs a password that exists before it does.
#
#    Outside the checkout, because the engine copies whatever .env the clone
#    ends with into a world-readable .env.default and writes the generated
#    compose file 644 (engine#173) -- and because ~/project is deleted and
#    re-cloned on every deploy, so a password kept there would stop matching
#    the user in the database that survived.
#
#    Written once and never rewritten, for that same reason: the setup script
#    is a no-op on an account that already has a super user.
CREDENTIALS="${DATA_HOME}/admin-credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on first deploy. This is the Omeka super user for this
# account -- sign in at https://<your-domain>/admin .
#
# Omeka Classic's installer is first-visitor-wins: /install creates the super
# user and asks nobody who they are. It was run at deploy time instead, with
# these values, and /install now answers "Omeka has already been installed".
# Change the password under your profile and this file stops being
# interesting.
OMEKA_ADMIN_USERNAME=admin
OMEKA_ADMIN_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
EOF
    chmod 600 "${CREDENTIALS}"
fi

# ---------------------------------------------------------------------------
# 5. A php.ini, on a platform that loads none.
#
#    `php --ini` in the base image answers "Loaded Configuration File: (none)"
#    (engine#185), so what is in force is PHP's compiled-in defaults --
#    including `upload_max_filesize = 2M`, and an Omeka item *is* a file
#    someone uploaded. What the file says and why is in its own comments.
#
#    It is put on the bind mount rather than left in the checkout because the
#    document root here is the repository root: a `panelalpha-php/` directory
#    at the top would be inside the served tree, and the generated vhost's
#    `^panelalpha[-.]` denial matches a *file* name, not a path component.
#    /data is not served at all.
if [ -f panelalpha-php.ini ]; then
    cp panelalpha-php.ini "${DATA_HOME}/php/zz-omeka.ini"
    chmod 644 "${DATA_HOME}/php/zz-omeka.ini"
fi

# ---------------------------------------------------------------------------
# 6. Directories Omeka writes into that the clone does not create writable.
#
#    application/logs is committed with only an errors.log.empty stub and
#    Omeka's logger appends to errors.log there when config.ini turns it on.
#    themes/ and plugins/ arrive as git submodules (the engine fetches them:
#    "Submodules fetched" in the deploy log) and the admin's own theme
#    configuration writes into files/theme_uploads, which is on the mount.
#
#    755, not 777: the container runs as this same account uid (the generated
#    compose file says `user: "<uid>:<gid>"`), so Apache is the owner.
mkdir -p application/logs
chmod 755 application/logs

echo "[omeka] prepared: files/ on ~/.panelalpha/omeka/files, .htaccess and config.ini installed"
