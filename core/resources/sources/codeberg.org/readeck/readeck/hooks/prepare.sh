#!/bin/bash
# Account shell, inside the account's own Docker daemon, cwd ~/project, after
# the clone and after overrides/docker-compose.yml has been laid down as
# ~/project/docker-compose.yml, before detection and before `up`.
#
# Four jobs:
#   1. The secret_key and the administrator password -- generated once into
#      ~/.panelalpha/readeck/, the one directory a redeploy cannot reach.
#   2. config.toml -- Readeck's config file, pinned so the secret_key is stable.
#   3. The image tag, from the one field of the checkout the recipe reads.
#   4. docker-compose.override.yml -- the account's uid/gid and the image, which
#      are not knowable until the account exists.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/readeck"
CONFIG="${DATA_HOME}/config.toml"
ENV_STORE="${DATA_HOME}/readeck.env"
NOTE="${DATA_HOME}/credentials.txt"
FALLBACK_IMAGE="codeberg.org/readeck/readeck:latest"

say() { echo "[panelalpha] readeck: $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL and a fork answers to the same one. Nothing
# below reads the checkout for anything but its git ref, but saying so turns a
# confusing result into one line in the log.
if ! grep -q 'readeck' go.mod 2>/dev/null; then
    say "WARNING: go.mod does not mention readeck -- this may not be a readeck checkout"
fi

# ---------------------------------------------------------------------------
# 1 + 2. Secrets and config.toml.
#
# ~/project is emptied and re-cloned on every deploy (engine#173,
# GitRepository.php:89) and ~ is chown root:root on every rebuild
# (Project.php:813); ~/.panelalpha is the only directory that both survives and
# belongs to the account. secret_key signs every session cookie, so it must not
# change on a redeploy, and Readeck has no fixed default for it -- `readeck
# config` returns a fresh random value each time when none is set. Generated
# once here and read back verbatim on every boot.
mkdir -p "${DATA_HOME}/data"
chmod 700 "${HOME}/.panelalpha" "${DATA_HOME}"

if [ ! -f "${CONFIG}" ]; then
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${CONFIG}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated. Deleting this
# resets the secret_key, which logs every session out; the data directory below
# is where the database and saved articles live.
[main]
log_level = "INFO"
# Signs every session cookie. Readeck has no fixed default: it generates a
# random one on first run, so it is pinned here to survive a redeploy.
secret_key = "${SECRET_KEY}"
data_directory = "data"

[server]
host = "0.0.0.0"
port = 8000

[database]
source = "sqlite3:data/db.sqlite3"

# Machine-sized defaults (7 job workers, 8 extractor workers) trimmed to a
# single hosting account. Raise them for a heavier bookmark load.
[worker]
num_workers = 2

[extractor]
workers = 2
EOF
    )
    say "generated config.toml and a secret_key in ${DATA_HOME}"
else
    say "reusing config.toml in ${DATA_HOME}"
fi
chmod 600 "${CONFIG}"

if [ ! -f "${ENV_STORE}" ]; then
    ADMIN_USER=admin
    # No '/', '+' or '=': read back by a POSIX shell, written into an env file
    # with no quoting, and typed into a login form.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Read only by the one-shot init container, only to create the administrator
# when the instance has none. Never read by the running server.
READECK_ADMIN_USER=${ADMIN_USER}
READECK_ADMIN_PASSWORD=${ADMIN_PASSWORD}
READECK_ADMIN_EMAIL=admin@localhost
EOF
    )
    (
        umask 077
        cat > "${NOTE}" <<EOF
Readeck administrator for this account
======================================

  username: ${ADMIN_USER}
  password: ${ADMIN_PASSWORD}

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password inside Readeck, this file is out of date and the one in the
application wins -- nothing here overwrites it.

Readeck has no public sign-up. Create further users from the application's
admin area (your avatar -> Admin -> Users) or with the CLI
(readeck user ...). The anonymous first-run form at /onboarding is closed the
moment this administrator exists, which is before the site is ever reachable.
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the administrator credentials in ${ENV_STORE}"
fi
chmod 600 "${ENV_STORE}" "${NOTE}" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 3. Which image.
#
# Default latest. A checkout parked on a release tag gets that release if the
# registry has it; everything else -- including the default branch -- gets
# latest. An operator who wants a pin sets READECK_IMAGE in the account's
# env_vars, which merges over the .env written below.
IMAGE="${FALLBACK_IMAGE}"
GIT_TAG="$(git describe --exact-match --tags HEAD 2>/dev/null | tr -d '\n' || true)"
if [ -n "${GIT_TAG}" ]; then
    CANDIDATE="codeberg.org/readeck/readeck:${GIT_TAG#v}"
    if docker pull --quiet "${CANDIDATE}" >/dev/null 2>&1; then
        IMAGE="${CANDIDATE}"
        say "checkout is at ${GIT_TAG}; running ${IMAGE}"
    else
        say "checkout is at ${GIT_TAG} but the registry has no such tag; using latest"
    fi
fi

# ~/project/.env is what compose interpolates ${READECK_IMAGE} from and what
# ProjectEnvironment::apply() merges the account's own env_vars over, so an
# operator-set READECK_IMAGE wins over this line. Nothing secret goes here: the
# file is world-readable and is copied to .env.default.
touch .env
sed -i '/^READECK_IMAGE=/d' .env
printf 'READECK_IMAGE=%s\n' "${IMAGE}" >> .env

# ---------------------------------------------------------------------------
# 4. The compose override: the account's uid/gid and the resolved image.
#
# Generated rather than shipped, because the uid is not knowable when a recipe
# is written and because a source directory may ship overrides/
# docker-compose.yml or overrides/docker-compose.override.yml but not both
# (AppConfigDirectory.php). Safe here: AppConfigBootstrap lays the recipe's own
# compose down before this hook runs, the engine writes no override of its own
# for a compose project, and Paths::composeFilesIn() adds
# `-f docker-compose.override.yml` whenever it exists. Running as the account's
# own uid keeps the database and saved articles under ~/.panelalpha/readeck
# account-owned and readable over SFTP rather than root-owned in the customer's
# own home.
APP_UID="$(id -u)"
APP_GID="$(id -g)"
cat > docker-compose.override.yml <<OVERRIDEEOF
# Written by PanelAlpha's Readeck recipe (hooks/prepare.sh) on every deploy.
# Edits here do not survive one -- put lasting changes in the account's env_vars
# or a compose file of your own named in COMPOSE_FILE.
services:
  init:
    image: ${IMAGE}
    user: "${APP_UID}:${APP_GID}"
  app:
    image: ${IMAGE}
    user: "${APP_UID}:${APP_GID}"
OVERRIDEEOF
say "wrote docker-compose.override.yml (image ${IMAGE}, uid ${APP_UID}:${APP_GID})"

# ---------------------------------------------------------------------------
# The page the customer reads next.
cat > "${DATA_HOME}/README.panelalpha.md" <<'MDEOF'
# Readeck on PanelAlpha

## Signing in

    https://<your domain>/login

username  `admin`
password  in the file `credentials.txt` beside this one

Change it under your avatar -> Profile. Create more users under your avatar ->
Admin -> Users. Readeck has no public sign-up, so nobody can create an account
on your instance -- the anonymous first-run form was closed for you when the
administrator above was created, before the site was ever reachable.

## What a stranger sees

A login page, from every path. Bookmarks are private to their owner, so an
anonymous visitor cannot read your bookmark list or any saved article unless
you deliberately create a public share link for one (a bookmark's Share menu).

## What lives where

    ~/.panelalpha/readeck/
      README.panelalpha.md   this file
      credentials.txt        the generated administrator password, 0600
      config.toml            the secret_key that signs your sessions; keep it
      readeck.env            the credentials init uses on a fresh instance
      data/db.sqlite3        the database: bookmarks, users, labels, sessions
      data/                  extracted articles, images and content-scripts

`~/project` is deleted and re-cloned on every deploy. Nothing you want to keep
belongs in it. Backing up means copying `~/.panelalpha/readeck/`.

## Things worth knowing

  * This runs the image Readeck publishes (codeberg.org/readeck/readeck),
    `latest` unless this project tracks a release tag. To move to a new Readeck,
    change the branch or tag and redeploy; the database migrates itself on the
    next start.
  * Readeck fetches and extracts the pages you save. It will not reach services
    on `localhost` -- from inside its container that is Readeck itself.
  * Mail is unconfigured. Readeck needs it only for optional password-reset and
    share emails; set [email] in config.toml if you want them.
MDEOF
chmod 600 "${DATA_HOME}/README.panelalpha.md" 2>/dev/null || true

say "prepared; credentials in ${NOTE}"
