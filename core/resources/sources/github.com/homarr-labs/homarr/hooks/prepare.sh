#!/bin/bash
# Account shell, inside the account's own Docker daemon, cwd ~/project, after
# the clone and after overrides/docker-compose.yml has been laid down as
# ~/project/docker-compose.yml, and before detection and before `up`.
#
# Three jobs:
#
#   1. Generate the two secrets that have to outlive a redeploy and put them
#      somewhere a redeploy cannot reach: the encryption key (without which the
#      server will not start, and which decrypts every integration credential
#      in the database) and the owner's password.
#   2. Decide which published image to run, from the one field of this checkout
#      the recipe uses: `version` in package.json.
#   3. Write the half of the compose file that cannot be written until the
#      account exists -- the account's uid and gid.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/homarr"
APPDATA="${DATA_HOME}/appdata"
SECRETS="${DATA_HOME}/secrets"
FALLBACK_IMAGE="ghcr.io/homarr-labs/homarr:v1.77.2"

say() { echo "[panelalpha] homarr: $*"; }

if [ ! -f Dockerfile ] || [ ! -f nginx.conf ] || [ ! -f pnpm-workspace.yaml ]; then
    echo "[panelalpha] homarr: no Dockerfile/nginx.conf/pnpm-workspace.yaml -- this is not a homarr checkout" >&2
    exit 1
fi

# ~ is chown root:root on every rebuild (Project.php:813) and ~/project is
# deleted and re-cloned (engine#173). ~/.panelalpha is the one directory under
# the home that belongs to the account -- measured on this platform: drwxr-xr-x
# <account> <account> -- so it is the only place a generated file both survives
# a deploy and stays readable by the customer.
mkdir -p "${APPDATA}" "${SECRETS}"
chmod 700 "${DATA_HOME}" "${SECRETS}"

# ---------------------------------------------------------------------------
# The encryption key
# ---------------------------------------------------------------------------
# Required, validated at import time, 64 hex characters exactly
# (packages/common/env.ts:13-25) -- a server without it throws before it
# listens, on every one of run.sh's restarts, behind an nginx that is already
# bound. That is what a stock deploy's 502 looks like once its other problem is
# fixed.
#
# Generated once and never again: it is the AES-256 key for every integration
# secret in the database (packages/common/src/encryption.ts:9). Rotating it
# does not reset anything, it makes the stored credentials unreadable.
#
# Not upstream's .env.example value. That file ships
# SECRET_ENCRYPTION_KEY=0000...0, which is 64 valid hex characters, so it
# passes validation and every API key on the dashboard ends up encrypted under
# a key printed in a public repository.
KEY_FILE="${SECRETS}/secret-encryption-key"
if [ ! -f "${KEY_FILE}" ]; then
    ( umask 077; openssl rand -hex 32 > "${KEY_FILE}" )
    say "generated an encryption key"
fi
chmod 600 "${KEY_FILE}"

# ---------------------------------------------------------------------------
# The owner's password
# ---------------------------------------------------------------------------
# Used once, by the init container, to set the password of the administrator it
# creates. Kept afterwards because it is the only copy the customer has; a
# regenerated one would be a password that no longer opens the site.
PW_FILE="${SECRETS}/admin-password"
if [ ! -f "${PW_FILE}" ]; then
    # Homarr only enforces 8-255 characters (userPasswordSchema), but its own
    # form asks for upper, lower, digit and symbol, so the generated one has
    # all four rather than depending on which check is in force. tr drops the
    # base64 characters that are painful to retype.
    ( umask 077; printf '%saA1!\n' "$(openssl rand -base64 18 | tr -d '/+=')" > "${PW_FILE}" )
    say "generated the administrator password"
fi
chmod 600 "${PW_FILE}"

# ---------------------------------------------------------------------------
# The image
# ---------------------------------------------------------------------------
# This recipe does not build the checkout. Upstream's Dockerfile is a
# pnpm/turbo monorepo build that took 427s of a 486s deploy on the batch host
# and dies with "ResourceExhausted: cannot allocate memory" on an 8-core
# account capped at 7.5 GB -- and, because the engine's generated compose file
# sits in the build context and changes every redeploy (engine#208), it would
# be paid again on every rebuild.
#
# The one thing taken from the checkout is the version, so that a customer who
# checks out an older tag gets that Homarr rather than whatever `latest` is
# today. If the tag is not published -- `dev` is ahead of the last release
# often enough -- the pinned fallback is used, and the log says which.
VERSION="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' package.json | head -1)"
IMAGE=""
if [ -n "${VERSION}" ]; then
    CANDIDATE="ghcr.io/homarr-labs/homarr:v${VERSION}"
    if docker pull --quiet "${CANDIDATE}" >/dev/null 2>&1; then
        IMAGE="${CANDIDATE}"
        say "running ${IMAGE} (package.json says ${VERSION})"
    else
        say "ghcr.io has no v${VERSION}; falling back"
    fi
fi
if [ -z "${IMAGE}" ]; then
    IMAGE="${FALLBACK_IMAGE}"
    docker pull --quiet "${IMAGE}" >/dev/null 2>&1 || true
    say "running ${IMAGE}"
fi

# ---------------------------------------------------------------------------
# The compose override
# ---------------------------------------------------------------------------
# Generated rather than shipped, because the uid is not knowable when a recipe
# is written, and because a source directory may hold overrides/
# docker-compose.yml or overrides/docker-compose.override.yml but not both
# (AppConfigDirectory.php:59-64). Safe to write here: AppConfigBootstrap lays
# the recipe's own compose down before this hook runs, the engine writes no
# override of its own for a compose project, and Paths::composeFilesIn() adds
# `-f docker-compose.override.yml` whenever the file exists.
APP_UID="$(id -u)"
APP_GID="$(id -g)"
cat > docker-compose.override.yml <<OVERRIDEEOF
# Written by PanelAlpha's Homarr recipe (hooks/prepare.sh) on every deploy.
# Edits here do not survive one -- put lasting changes in a compose file of
# your own and name it in COMPOSE_FILE.
services:
  app:
    image: ${IMAGE}
    environment:
      # The image's entrypoint.sh chowns /appdata, the nginx runtime
      # directories and the Next.js cache to these and then su-execs the
      # server. Without them everything under ~/.panelalpha/homarr/appdata --
      # the customer's database and uploads -- would be root-owned inside their
      # own home, which is not backupable and not readable over SFTP.
      PUID: "${APP_UID}"
      PGID: "${APP_GID}"
  init:
    image: ${IMAGE}
OVERRIDEEOF
say "wrote docker-compose.override.yml (app as ${APP_UID}:${APP_GID})"

# ---------------------------------------------------------------------------
# The page the customer reads next
# ---------------------------------------------------------------------------
cat > "${DATA_HOME}/README.panelalpha.md" <<'MDEOF'
# Homarr on PanelAlpha

## Signing in

    https://<your domain>/auth/login

username  `owner`
password  in the file `secrets/admin-password` beside this one

Change it under your avatar -> Preferences, or create your own account under
Manage -> Users and delete this one. Nothing here recreates it afterwards: the
deploy only creates an administrator when the database has none at all.

## What a stranger sees

A login page. Homarr's installer has already been completed for you, and no
board is published, so an anonymous visitor is redirected to `/auth/login` from
every path. The only thing they can read is `/api/health/live`, which reports
whether the database and Redis are up and nothing else.

If you want a public dashboard, set one under **Manage -> Settings -> Board ->
Home board** and mark that board public in its settings. Understand what that
means first: everything on a public board is readable by anyone who knows your
domain -- tile names, the URLs behind them, and the data any widget on it
displays. Internal hostnames and the contents of integrations belong on a
board you do not publish.

## Users and groups

Manage -> Users, Manage -> Groups. A group carries permissions; `admin` implies
all of them. Give a board to a group under the board's own settings rather than
making it public, if only some people should see it.

## What lives where

    ~/.panelalpha/homarr/
      README.panelalpha.md          this file
      secrets/admin-password        the generated password, plain text, 0600
      secrets/secret-encryption-key the key your integration credentials are
                                    encrypted with. Back it up. Lose it and
                                    every stored API key becomes unreadable;
                                    change it and the same thing happens.
      appdata/db/db.sqlite          everything: boards, widgets, users,
                                    integrations, sessions
      appdata/redis/                Redis's dump, a cache, safe to delete
      appdata/media/                images you upload to Homarr

`~/project` is deleted and re-cloned on every deploy. Nothing you want to keep
belongs in it.

## Things worth knowing

  * This runs the image Homarr publishes, pinned to the version in the
    checkout's `package.json`. Nothing else in `~/project` is used. To move to
    a new Homarr, change the branch or tag this project tracks and redeploy;
    the database migrates itself on the next start.
  * Widgets that talk to other applications need an **integration** (Manage ->
    Integrations) holding that application's URL and API key. Those keys are
    encrypted in the database with the key above.
  * Homarr will not reach services on `localhost` -- from inside its container
    that is Homarr itself. Use the address the rest of your network uses.
  * Backing up means copying `~/.panelalpha/homarr/`. Restoring means putting
    it back and redeploying.
MDEOF
chmod 600 "${DATA_HOME}/README.panelalpha.md"

say "prepared; credentials in ${SECRETS}, notes in ${DATA_HOME}/README.panelalpha.md"
