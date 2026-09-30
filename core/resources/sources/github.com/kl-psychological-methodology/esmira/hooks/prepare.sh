#!/bin/bash
# Account shell, after the clone and before the build. Runs on every deploy.
#
# Six jobs. None of them can be done from inside the container, and none of
# them can be left to the checkout:
#
#   1. fail loudly when the ESMira-web submodule did not come down, because
#      that is the whole application and the engine only *warns* about it
#   2. create the two directories the compose override bind-mounts, *before*
#      compose does -- a missing bind-mount source becomes a root-owned
#      directory the account cannot write to
#   3. create ESMira-web/dist/backend/config inside the checkout, so the nested
#      bind mount has a real, account-owned target
#   4. generate the administrator credentials, outside ~/project so they
#      survive the clone
#   5. write the credentials file the deploy log points the owner at
#   6. move a root compose file out of the checkout if one ever appears,
#      by name
#
# Nothing here initialises the server: that is files/panelalpha-esmira-setup.php,
# which runs inside the container on the install and upgrade stages.
set -e
cd ~/project

# ---------------------------------------------------------------------------
# 1. The submodule.
#
# github.com/KL-Psychological-Methodology/ESMira is a *meta* repository: four
# files, two directory stubs and a .gitmodules. The server is
# ESMira-web (and ESMira-apps is the phone client, which has no business here
# but arrives anyway). GitRepository::fetchSubmodules() runs
# `git submodule update --init --depth=1 --recursive` after the clone and
# *swallows* a failure into a warn line (GitRepository.php:589-607) -- the
# right call for a repository whose submodules are optional, and fatal for this
# one, where an empty ESMira-web/ means there is no application at all.
#
# Measured: both submodules resolve to their default-branch tips today, so the
# shallow fetch finds them; a pin that moves off the tip is exactly the case
# where `--depth=1` stops working and this message is what the log would say.
if [ ! -f ESMira-web/src/index.php ]; then
    echo "[esmira] ESMira-web/src/index.php is missing." >&2
    echo "[esmira] This repository is a meta repository: the server lives in the" >&2
    echo "[esmira] ESMira-web submodule and the clone did not bring it down." >&2
    exit 1
fi

# ESMira-apps is the Android/iOS client: a Kotlin Multiplatform tree, 6.1 MB in the checkout,
# nothing in it is served and nothing in it is built here. It is left in place
# rather than deleted -- it is part of the checkout the account cloned and
# `git status` should stay clean -- but it is outside the document root
# (ESMira-web/dist), so it has no URL.

STORE="${HOME}/.panelalpha"
# Account homes are root-owned 755, so this has to be created rather than
# written into $HOME directly. It is also the only place anything survives a
# deploy: GitRepository::cloneConfiguredRepository empties ~/project before
# every clone (engine #173).
mkdir -p "${STORE}"
chmod 700 "${STORE}"

# ---------------------------------------------------------------------------
# 2. The two directories that hold everything the account owns.
#
#   ${STORE}/esmira/data    -> /data          holds esmira_data/
#   ${STORE}/esmira/config  -> .../backend/config  holds configs.php
#
# They are split because ESMira splits them, and not by choice on either side:
#
#   * the data folder is a *configured* path (Configs::get('dataFolder_path'),
#     backend/fileSystem/PathsFS.php:21), so the recipe can put it anywhere --
#     and puts it outside the document root, where no study file and no
#     response file has a URL.
#   * configs.php is not. `Paths::FILE_CONFIG` is a class constant,
#     `DIR_BASE . 'backend/config/configs.php'` (backend/Paths.php:8), so it is
#     always inside the document root. The only way to keep it across a
#     redeploy is to mount it there, which is what upstream's own Dockerfile
#     does (`VOLUME /var/www/html/backend/config/`).
#
# Created here and not by Docker, and that is the point of doing it in the
# hook: compose creates a missing bind-mount source itself, owned by root and
# mode 755, and the app container runs as the account uid -- which could then
# write neither esmira_data nor configs.php.
DATA="${STORE}/esmira/data"
CONFIG="${STORE}/esmira/config"
mkdir -p "${DATA}" "${CONFIG}"
chmod 700 "${STORE}/esmira" "${DATA}" "${CONFIG}"

# ---------------------------------------------------------------------------
# 3. The mount point inside the checkout.
#
# ${CONFIG} is mounted *inside* the ./:/app bind mount, at
# /app/ESMira-web/dist/backend/config. Docker creates a missing target itself,
# but a target inside a bind mount is created on the host, as root -- and the
# webpack build has not run yet when this hook does, so dist/ does not exist.
#
# Creating it here as the account means the mount always lands on a directory
# the account owns, whatever the build then does. The build cannot remove it:
# webpack's `output.clean` is scoped to `output.path`, which is dist/frontend
# (build_configs/config.base.js), and CopyWebpackPlugin merges src/backend into
# dist/backend rather than replacing it.
mkdir -p ESMira-web/dist/backend/config

# ---------------------------------------------------------------------------
# 4. The administrator account and the install settings.
#
# The first administrator is the one secret in an ESMira server, and its login
# is the engine's (`credentials:` in panelalpha.yaml), delivered in
# ~/.panelalpha/app-credentials.env. esmira-app.env keeps the two install
# settings. Both reach the container by `env_file:` at a path that climbs out
# of the checkout, rather than through .env: ProjectEnvironment::apply() copies
# .env to .env.default at mode 644 (engine #173). ComposeEnvFiles refuses a path
# with a `..` segment, so the engine does not create esmira-app.env on the
# stack's behalf; this hook is what guarantees it exists before compose reads it.
APP_ENV="${STORE}/esmira-app.env"

if [ ! -f "${APP_ENV}" ]; then
  (
    # In a subshell so it covers the redirection that creates the file and does
    # not leak into the rest of this script, where a 077 default would leave a
    # checkout the engine cannot walk.
    umask 077
    cat > "${APP_ENV}" <<EOF
# Generated by the PanelAlpha ESMira recipe, once per account.
# Read by the app container through env_file:, and consumed by
# panelalpha-esmira-setup.php on the install stage.
ESMIRA_DATA_LOCATION=/data
# The name the front page shows in its header. ESMira's own wizard asks for it
# and leaves it empty otherwise -- index.php echoes it straight into
# ESMira.init(), so an unset value is a site with no name anywhere. Read only
# on the install stage; changing it afterwards is done in the web interface,
# which writes it to configs.php and outranks this.
ESMIRA_SERVER_NAME=ESMira
EOF
  )
fi
chmod 600 "${APP_ENV}"

# ---------------------------------------------------------------------------
# 6. Compose files the repository root does not have today.
#
# ESMira ships a docker-compose.yml, but inside the ESMira-web submodule --
# and RuntimeSidecars::runtimeSidecarsFromProject() only reads the *repository
# root* (RuntimeSidecars.php:38-39), so it is not harvested today. Were one to
# appear at the root, the php strategy would mine it for backing services and
# this account would grow whatever upstream's CI uses.
#
# Moved by name and never by a `docker-compose.*.yml` glob, which matches this
# recipe's own override -- written into the checkout by bootstrap *before* this
# hook runs -- and would take the healthcheck, the /data mount and the
# credentials env_file with it while the deploy still reported success.
mkdir -p "${STORE}/upstream-compose"
for f in docker-compose.yml docker-compose.yaml compose.yml compose.yaml; do
    [ -f "$f" ] || continue
    mv -f "$f" "${STORE}/upstream-compose/"
    echo "[esmira] moved $f out of the checkout"
done

echo "[esmira] prepare finished; data ${DATA}, config ${CONFIG}"
