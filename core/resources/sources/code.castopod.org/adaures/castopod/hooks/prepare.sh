#!/bin/bash
# Account shell, after the clone and before the build. Nothing here can know
# the database credentials or the account's public URL -- the engine settles
# both while it writes the compose file, after this hook -- so everything that
# needs them is in files/panelalpha/castopod-setup.sh instead.
set -e
cd ~/project

# Everything this account accumulates that must outlive a redeploy lives here.
# A redeploy clears and re-clones ~/project (engine#173), so uploaded audio
# under the checkout would be gone on the next deploy. ~ itself is root-owned
# 0755 and nothing can be created in it; ~/.panelalpha is created with the
# account and belongs to it.
DATA_HOME="${HOME}/.panelalpha/castopod"
mkdir -p "${DATA_HOME}/media"
chmod 700 "${DATA_HOME}"

# 1. Uploaded media, out of the document root's own directory tree.
#
#    Modules\Media\Config\Media hard-codes `$storage = ROOTPATH . 'public'` and
#    `$root = 'media'`, so every cover, every avatar and every episode audio
#    file is written to ~/project/public/media -- inside the checkout the next
#    deploy deletes. `media.storage` is settable from .env, but moving the
#    directory out of public/ would also take it out of what Apache serves,
#    and Castopod serves media as plain static files under media.baseURL.
#
#    A symlink is the arrangement that keeps both: the path Castopod writes to
#    and the URL a podcast client fetches stay `public/media/...`, and the
#    bytes live on the bind mount. Absolute, pointing at the mount point rather
#    than at ~/.panelalpha: it is resolved inside the container, where the
#    override file mounts ~/.panelalpha/castopod at /data. public/.htaccess
#    already carries `Options +FollowSymlinks`, which is what makes Apache
#    follow it.
#
#    The repository's own public/media holds three index.html stubs and the
#    empty podcasts/, persons/ and site/ directories; they are copied across
#    once so a fresh account starts with the same shape, and never afterwards.
if [ -d public/media ] && [ ! -L public/media ]; then
    cp -rn public/media/. "${DATA_HOME}/media/" 2>/dev/null || true
    rm -rf public/media
fi
ln -sfn /data/media public/media

# 2. The directories CodeIgniter writes into. .gitignore keeps their contents
#    out of the clone but the directories themselves are tracked; writable/
#    plugins state and the GeoIP database are not, and a missing
#    writable/session is a 500 on the first request rather than a warning.
#    755, not 777: the container runs as this same account uid, so Apache is
#    the owner. Readable by the engine (www-data) as well, which walks this
#    tree looking for the document root and stops the deploy on a directory it
#    cannot open.
mkdir -p writable/cache writable/logs writable/session writable/temp \
         writable/uploads writable/debugbar
chmod 755 writable writable/cache writable/logs writable/session \
          writable/temp writable/uploads writable/debugbar

# 3. Per-account secrets, generated once and kept outside the checkout so a
#    redeploy neither regenerates them nor loses them.
#
#    analytics.salt is not optional decoration: Castopod hashes listener IPs
#    with it, modules/Install checks it is present before it will go on, and
#    upstream's own container bootstrap refuses to start without one. It must
#    also stay the same across redeploys or every returning listener counts as
#    a new one.
if [ ! -f "${DATA_HOME}/analytics.salt" ]; then
    ( umask 077
      LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 64 > "${DATA_HOME}/analytics.salt" )
    chmod 600 "${DATA_HOME}/analytics.salt"
fi

#    The superadmin. Castopod's install wizard is first-visitor-wins: with a
#    complete .env and a reachable database, Modules\Install\Controllers\
#    InstallController::index() migrates, seeds and then renders the
#    create-superadmin form to whoever asked for /cp-install, and
#    createSuperAdminAction() makes that visitor the instance owner
#    (`is_owner => true`) with no authentication of any kind in front of it.
#    On an account that has just been given a public HTTPS name, that window is
#    open to anyone who knows the address. panelalpha/castopod-setup.sh closes
#    it on the install stage, from these credentials, before Apache binds:
#    once an is_owner user exists the same controller throws
#    PageNotFoundException and /cp-install is a 404.
#
#    Written once and never rewritten: the setup script is a no-op on an
#    account that already has an owner, so a regenerated password would stop
#    matching the user in a database that survived the redeploy.
CREDENTIALS="${DATA_HOME}/admin-credentials"
if [ ! -f "${CREDENTIALS}" ]; then
    umask 077
    cat > "${CREDENTIALS}" <<EOF
# Written by PanelAlpha on first deploy. This is the Castopod instance owner
# for this account -- sign in at https://<your-domain>/cp-auth/login .
#
# Castopod's install wizard creates the first superadmin, and on a public
# address that is whoever loads /cp-install first. It was run at deploy time
# instead, with these values, and /cp-install now answers 404. Change the
# password under your profile and this file stops being interesting.
# The email is not here because this hook does not know the account's public
# name yet; panelalpha/castopod-setup.sh works it out from APP_URL, uses it to
# create the owner and writes it back into this file.
CASTOPOD_ADMIN_USERNAME=admin
CASTOPOD_ADMIN_PASSWORD=$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)
EOF
    chmod 600 "${CREDENTIALS}"
fi

# 4. A .env with nothing secret in it.
#
#    The engine copies whatever .env the clone ends with into .env.default at
#    mode 644, which every other tenant on the host can read (engine#173) -- so
#    the real file, with the database password and the analytics salt in it, is
#    written by panelalpha/castopod-setup.sh inside the container instead,
#    after that copy has been taken, and chmod 600 there.
#
#    Written rather than left absent so that the copy is this file and not
#    Castopod's .env.example, whose `database.default.password="****"` and
#    `app.baseURL="https://YOUR_DOMAIN_NAME/"` are what a deploy without this
#    recipe actually ran with.
cat > .env <<'EOF'
# Written by PanelAlpha. The real configuration -- the account's database
# credentials, its public URL and its analytics salt -- is written into this
# file by panelalpha/castopod-setup.sh when the container starts, and is
# deliberately not here: the engine keeps a world-readable copy of whatever
# the clone leaves behind (.env.default).
#
# Per-account values that must survive a redeploy are in ~/.panelalpha/castopod.
CI_ENVIRONMENT="production"
EOF
chmod 600 .env

echo "[castopod] prepared: media on ~/.panelalpha/castopod/media, secrets generated"
