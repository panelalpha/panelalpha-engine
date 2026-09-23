#!/bin/bash
# Account shell, after the clone and before the build.
#
# Five things that have to be true before anything is built or served, and that
# nothing later in the deploy can do:
#   1. the web installer has to be shut, and it is open in a fresh clone;
#   2. the admin password has to outlive the checkout;
#   3. ffmpeg, ffprobe and mediainfo have to exist somewhere, because the
#      shared PHP base image has none of them and ClipBucket is a transcoder
#      with a website attached;
#   4. the uploaded media has to live outside ~/project, which every deploy
#      re-clones (engine#173);
#   5. the directories .gitignore keeps out of the repository have to exist.
set -e
cd ~/project

log() { echo "[clipbucket] $*"; }

# Everything this account accumulates that must outlive a redeploy. ~ itself is
# root-owned 0755 and nothing can be created directly in it; ~/.panelalpha is
# created with the account and belongs to it. engine#173 also writes
# .env.default and the generated docker-compose.yml into the checkout 0644 and
# readable by every other tenant, which is the other reason nothing secret
# belongs in ~/project.
DATA_HOME="${HOME}/.panelalpha/clipbucket"
mkdir -p "${DATA_HOME}/bin" "${DATA_HOME}/files"
chmod 700 "${HOME}/.panelalpha" "${DATA_HOME}"

# ------------------------------------------------- 1. shut the installer --
#
# upload/files/temp/install.me is COMMITTED. upload/files/temp/.gitignore is
#
#     /*
#     # But not these files...
#     !.gitignore
#     !install.me
#
# so upstream keeps the lock file in the repository on purpose, and every clone
# of this repository arrives with the installer unlocked.
#
# What that opens is not a wizard you have to be quick about. cb_install/ajax.php
# checks nothing but that file (ajax.php:11), and its `create_files` step writes
# upload/includes/config.php by substituting $_POST['dbhost'], ['dbuser'],
# ['dbpass'], ['dbname'], ['dbport'] and ['dbprefix'] into cb_install/config.php
# -- a PHP file, into single-quoted PHP strings, with no escaping of any kind
# (ajax.php:183-195). A password of
#
#     ' . file_get_contents($_GET['f']) . '
#
# is written into the file the application includes on every request. That is
# unauthenticated remote code execution on a public HTTPS address, reachable by
# anyone who knows it, and it is live from the first second the site answers.
# The same endpoint will also open a MySQL connection to any host:port it is
# given and report the result, and the `adminsettings`/`finish` path sets the
# admin account's username, password and email from POST.
#
# Deleting the file is upstream's own lock: functions_install.php:2-11 redirects
# /cb_install/ to the site root when it is absent, and ajax.php:11 returns
# without doing anything. It is deleted here rather than in the container's
# install stage because this hook runs before anything is built, let alone
# served -- there is no window at all.
#
# install.me.not is NOT created as a substitute. Read the gate carefully: with
# install.me absent but install.me.not present, functions_install.php skips both
# the redirect and the `lock` mode and the installer is fully open again. The
# only safe state is neither file.
rm -f upload/files/temp/install.me upload/files/temp/install.me.not \
      upload/files/temp/development.dev
log "removed upload/files/temp/install.me (shipped unlocked by upstream)"

# ------------------------------------------------------ 2. admin password --
#
# Every deploy re-clones over ~/project while the account's MySQL database --
# and the password hash in cb_users -- stays where it is, so a password
# generated beside the code would be a new password on every redeploy, matching
# nothing.
#
# ClipBucket's own wizard defaults this field to the literal string `admin`
# (cb_install/modes/adminsettings.php:31). Nothing here ever uses a default.
CREDENTIALS="${DATA_HOME}/admin-credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on the first deploy. This is the ClipBucket
# administrator for this account -- sign in at
# https://<your-domain>/signin and then open /admin_area/ .
#
# ClipBucket's web installer creates this account, and on a public address that
# is whoever loads /cb_install/ first -- so it was run from the deploy instead,
# with these values, and the installer is shut. Change the password from the
# admin area and this file stops being interesting.
CLIPBUCKET_ADMIN_USERNAME=admin
CLIPBUCKET_ADMIN_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
EOF
    chmod 600 "${CREDENTIALS}"
fi

# The container cannot see ~/.panelalpha/clipbucket/admin-credentials as a
# file it can parse before /data is mounted, and /data *is* mounted -- but the
# install script reads it from /data, so nothing is copied into the checkout.
# Stated here because every other PHP recipe on this engine copies a dotfile
# into ~/project and this one deliberately does not: with a /data mount there is
# no reason to put the password inside the tree engine#173 clones over.

# ----------------------------------------------------------- 3. the tools --
#
# The shared PHP base image has no ffmpeg, no ffprobe and no mediainfo:
#
#   docker run --rm panelalpha/php:8.3-apache-bookworm-pa20260910 \
#       sh -c 'command -v ffmpeg ffprobe mediainfo'   ->  nothing
#
# and there is no way for a recipe to add an apt package to it. The manifest's
# `requires` names toolchains the engine knows; PhpBaseImage's `extras` are
# php extensions passed to install-php-extensions
# (core/app/Lib/Deploy/CacheManager/PhpBaseImage.php:254-270), not packages;
# and resources/deploy/templates/dockerfile/php-base.stub installs a fixed
# `git unzip` and nothing a manifest can reach.
#
# ClipBucket is not "nicer with ffmpeg". cb_install/functions_install.php:95-104
# lists FFmpeg, FFprobe and MediaInfo as required software and
# functions_install.php:127-132 makes only MySQL Client and Git skippable, so
# upstream's own installer will not let you past the precheck without them --
# and at runtime FFMpeg::ClipBucket() is the whole of what an upload becomes.
#
# So the binaries are fetched once per account, as static builds, into
# ~/.panelalpha/clipbucket/bin, which the compose override mounts at /data/bin.
# Once, not per deploy: the directory survives the re-clone that ~/project does
# not. Fatal if it fails, because a video site that cannot accept a video should
# not be reported as a successful deploy.
BIN="${DATA_HOME}/bin"

if [ ! -x "${BIN}/ffmpeg" ] || [ ! -x "${BIN}/ffprobe" ]; then
    log "fetching static ffmpeg/ffprobe (once per account)"
    tmp=$(mktemp -d)
    trap 'rm -rf "${tmp}"' EXIT
    # johnvansickle's builds are the ones ffmpeg.org links to for static Linux
    # x86_64. They are GPLv3 (the tarball carries GPLv3.txt) and are downloaded
    # by the account at deploy time rather than redistributed by PanelAlpha.
    if ! curl -fsSL --retry 3 --max-time 300 \
        -o "${tmp}/ffmpeg.tar.xz" \
        https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz
    then
        log "could not download ffmpeg. ClipBucket cannot convert a video without" >&2
        log "it and its own installer refuses to run without it, so the deploy stops here." >&2
        exit 1
    fi
    tar -xJf "${tmp}/ffmpeg.tar.xz" -C "${tmp}"
    src=$(find "${tmp}" -maxdepth 1 -type d -name 'ffmpeg-*-amd64-static' | head -1)
    install -m 0755 "${src}/ffmpeg"  "${BIN}/ffmpeg"
    install -m 0755 "${src}/ffprobe" "${BIN}/ffprobe"
    rm -rf "${tmp}"
    trap - EXIT
fi

if [ ! -x "${BIN}/mediainfo" ]; then
    log "fetching static mediainfo (once per account)"
    tmp=$(mktemp -d)
    trap 'rm -rf "${tmp}"' EXIT
    # MediaArea's Lambda build is the only self-contained MediaInfo CLI they
    # publish: one binary with no libmediainfo/libzen to unpack beside it.
    # Verified to run in the bookworm base image.
    if curl -fsSL --retry 3 --max-time 300 -o "${tmp}/mi.zip" \
        https://mediaarea.net/download/binary/mediainfo/26.05/MediaInfo_CLI_26.05_Lambda_x86_64.zip
    then
        unzip -qo "${tmp}/mi.zip" -d "${tmp}/mi"
        install -m 0755 "${tmp}/mi/bin/mediainfo" "${BIN}/mediainfo"
    else
        # Not fatal, unlike ffmpeg. MediaInfo is a hard requirement of
        # upstream's *precheck* and only a fallback at runtime: FFMpeg::
        # getFileInfo() calls it for the duration when ffprobe could not give
        # one and for the "Original width/height" of anamorphic material
        # (includes/classes/ffmpeg.class.php:126,134). Everything else comes
        # from ffprobe.
        log "could not download mediainfo; continuing without it (see README)" >&2
    fi
    rm -rf "${tmp}"
    trap - EXIT
fi

# ---------------------------------------------------- 4. the media, moved --
#
# Every byte a user uploads goes under upload/files: DirPath::get()
# (includes/constants.php:33-47) puts videos, original files, thumbs, photos,
# avatars, backgrounds, logos, subtitles, the conversion queue, the mass-upload
# staging area and the per-video conversion logs in
# upload/files/<name>/ -- inside the checkout that the next deploy deletes,
# while the cb_video rows that name them survive. A redeploy would leave a
# catalogue of videos that no longer exist.
#
# So upload/files is a mount point rather than a directory: the compose override
# bind-mounts ~/.panelalpha/clipbucket/files over it. A mount rather than a
# symlink because the whole directory has to move, and because the paths and the
# URLs both have to keep working -- ClipBucket serves thumbnails, posters and
# the video files themselves as plain static URLs under `files/...`, and
# upload/.htaccess's own rules are written against that prefix.
#
# The repository's own upload/files is not empty: files/videos/no_video.mp4 and
# example.mp4, files/thumbs/processing.jpg, files/photos/no-photo_t.png and
# no-photo_m.png, files/ai/ and a .htaccess. They are copied across without
# clobbering, on every deploy, so a fresh account starts with the right shape
# and a version bump that adds a new default file still gets it.
cp -rn upload/files/. "${DATA_HOME}/files/" 2>/dev/null || true

# ...and the same lock file again, on the other side of the mount. What the
# container sees at /app/upload/files is this directory, not the checkout, so
# an install.me copied here on some earlier deploy would re-open the installer
# no matter what was deleted from ~/project.
rm -f "${DATA_HOME}/files/temp/install.me" \
      "${DATA_HOME}/files/temp/install.me.not" \
      "${DATA_HOME}/files/temp/development.dev"

# The directories .gitignore keeps out of the clone. Missing conversion_queue or
# logs is a failed conversion rather than a warning.
mkdir -p "${DATA_HOME}/files/conversion_queue" "${DATA_HOME}/files/temp" \
         "${DATA_HOME}/files/logs" "${DATA_HOME}/files/videos" \
         "${DATA_HOME}/files/original" "${DATA_HOME}/files/thumbs" \
         "${DATA_HOME}/files/photos" "${DATA_HOME}/files/avatars" \
         "${DATA_HOME}/files/backgrounds" "${DATA_HOME}/files/logos" \
         "${DATA_HOME}/files/subtitles" "${DATA_HOME}/files/mass_uploads" \
         "${DATA_HOME}/files/category_thumbs"
# The container runs as this same account uid (the generated compose file says
# `user: "<uid>:<gid>"`), so the account owns everything either way and 755 is
# enough. The engine (www-data) walks this tree looking for the document root
# and stops the deploy on a directory it cannot open.
chmod -R u+rwX,go+rX "${DATA_HOME}/files"

# The per-video conversion logs are on the mount, inside the document root, and
# upstream protects only files/temp/. Measured before this file existed:
#
#   GET /files/logs/2026/09/20/<file_name>.log   200, 5,991 bytes
#
# and <file_name> is in the page HTML of every video, so the logs are
# enumerable rather than merely reachable. They carry absolute container paths,
# the ffmpeg command line, every source and output stream's codec and bitrate,
# and whatever ffmpeg had to say about a file the account uploaded privately.
# Nothing ever fetches them by URL -- there is no DirPath::getUrl('logs') call
# anywhere in the application; the admin area reads them off the filesystem.
#
# Written here rather than shipped in files/ because the directory this has to
# land in is the *mount*, not the checkout.
mkdir -p "${DATA_HOME}/files/logs"
cat > "${DATA_HOME}/files/logs/.htaccess" <<'HTACCESS'
# Written by PanelAlpha. Conversion logs are read from disk by the admin area
# and are never fetched over HTTP; they name absolute paths and describe files
# an account may not have published.
Require all denied
HTACCESS

# And the ones inside the checkout that are not on the mount: Smarty's compiled
# templates and ClipBucket's own view cache.
mkdir -p upload/cache/views upload/cache/userfeeds upload/cache/comments
chmod -R 755 upload/cache

# --------------------------------------------------- developer files, denied --
#
# upload/ is the document root and upstream leaves composer.json and
# package.json in it; both answered 200 on a live deploy. They name the
# dependency set and the exact ClipBucket version. Appended to upstream's own
# .htaccess rather than replacing it, because the rest of that file is
# ClipBucket's front-controller rewriting; a later directive of the same name
# wins. Guarded so a redeploy does not stack copies -- though the clone that
# precedes this hook has already removed any.
if ! grep -q 'PanelAlpha: developer files' upload/.htaccess 2>/dev/null; then
    cat >> upload/.htaccess <<'HTACCESS'

# PanelAlpha: developer files. Neither of these is a page, and both describe
# the installation to whoever asks.
<FilesMatch "^(?:composer\.(?:json|lock)|package(?:-lock)?\.json)$">
    Require all denied
</FilesMatch>
HTACCESS
fi

# ------------------------------------------------- 5. display_errors, off --
#
# engine#185 leaves the platform with display_errors=1 and no php.ini. The ini
# in files/panelalpha/php turns it off for both SAPIs, but that file is only
# read because the compose override sets PHP_INI_SCAN_DIR; this is here to say
# the .htaccess route was considered and is not used -- upload/.htaccess is
# upstream's front-controller rewriting and this recipe does not edit it.

log "prepared: installer shut, tools in ~/.panelalpha/clipbucket/bin, media on the mount"
