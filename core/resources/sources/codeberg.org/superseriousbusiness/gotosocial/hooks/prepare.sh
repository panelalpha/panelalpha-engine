#!/bin/bash
# Account shell, inside the account's own Docker daemon, cwd ~/project, after the
# clone and after overrides/docker-compose.yml has been laid down as
# ~/project/docker-compose.yml, before detection and before `up`.
#
# GoToSocial keeps its whole identity in the SQLite database (the instance
# account's keypair is a row in it), so unlike Readeck there is no external
# secret to pin -- the one job that matters is that the database directory
# survives a redeploy. Four things:
#   1. The administrator login is the engine's (`credentials:` in
#      panelalpha.yaml), written to ~/.panelalpha/app-credentials.env before
#      this hook runs; nothing to generate here.
#   2. The persistent storage directory (SQLite DB + media + instance keypair).
#   3. The image tag, from the checkout's git ref.
#   4. docker-compose.override.yml -- the account's uid/gid and the image.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/gotosocial"
STORAGE="${DATA_HOME}/storage"
FALLBACK_IMAGE="docker.io/superseriousbusiness/gotosocial:latest"

say() { echo "[panelalpha] gotosocial: $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about? Nothing below reads the
# checkout except its git ref, but saying so turns a confusing result into one
# log line.
if ! grep -q 'gotosocial' go.mod 2>/dev/null; then
    say "WARNING: go.mod does not mention gotosocial -- this may not be a gotosocial checkout"
fi

# ---------------------------------------------------------------------------
# 2. Persistent data.
#
# ~/project is emptied and re-cloned on every deploy and ~ is
# chowned root on every rebuild (Project.php); ~/.panelalpha is the only
# directory that both survives and belongs to the account. The SQLite database,
# the media store and the instance keypair all live under storage/, so a redeploy
# that lost it would regenerate the keypair and break the instance's federation
# identity for good.
mkdir -p "${STORAGE}"
chmod 700 "${HOME}/.panelalpha" "${DATA_HOME}"

# ---------------------------------------------------------------------------
# 3. Which image. Default latest; a checkout parked on a release tag gets that
# release if the registry has it. An operator who wants a pin sets GTS_IMAGE in
# the account's env_vars, which merges over the .env written below.
IMAGE="${FALLBACK_IMAGE}"
GIT_TAG="$(git describe --exact-match --tags HEAD 2>/dev/null | tr -d '\n' || true)"
if [ -n "${GIT_TAG}" ]; then
    CANDIDATE="docker.io/superseriousbusiness/gotosocial:${GIT_TAG#v}"
    if docker pull --quiet "${CANDIDATE}" >/dev/null 2>&1; then
        IMAGE="${CANDIDATE}"
        say "checkout is at ${GIT_TAG}; running ${IMAGE}"
    else
        say "checkout is at ${GIT_TAG} but the registry has no such tag; using latest"
    fi
fi

# ~/project/.env is what compose interpolates ${GTS_IMAGE} from and what
# ProjectEnvironment::apply() merges the account's own env_vars over, so an
# operator-set GTS_IMAGE wins over this line. Nothing secret goes here.
touch .env
sed -i '/^GTS_IMAGE=/d' .env
printf 'GTS_IMAGE=%s\n' "${IMAGE}" >> .env

# ---------------------------------------------------------------------------
# 4. The compose override: the account's uid/gid and the resolved image.
# Running as the account's own uid keeps the database and media under
# ~/.panelalpha/gotosocial account-owned and readable over SFTP rather than
# root-owned (or uid-1000-owned) in the customer's own home.
APP_UID="$(id -u)"
APP_GID="$(id -g)"
cat > docker-compose.override.yml <<OVERRIDEEOF
# Written by PanelAlpha's GoToSocial recipe (hooks/prepare.sh) on every deploy.
# Edits here do not survive one -- put lasting changes in the account's env_vars.
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
# GoToSocial on PanelAlpha

## Signing in

    https://<your domain>/

email     `admin@localhost` (username `admin`)
password  returned by GET /projects/{name}/app-credentials (MCP app_credentials_get)

Change it and manage the instance under Settings -> Administration.

## What a stranger sees

The instance landing page and the public web profile/timeline of any account you
choose to make public. The client API refuses unauthenticated access to private
data. Signup is invite-only, so nobody can create an account without an invite.

## What lives where

    ~/.panelalpha/gotosocial/
      README.panelalpha.md   this file
      storage/sqlite.db      the database: accounts, statuses, and the INSTANCE
                             KEYPAIR that is your server's federation identity
      storage/               the media store (avatars, attachments) and cache

`~/project` is deleted and re-cloned on every deploy. Nothing you want to keep
belongs in it. Backing up means copying `~/.panelalpha/gotosocial/`. If you lose
`storage/sqlite.db` your instance loses its identity and can no longer federate
under the same name.

## Things worth knowing

  * This runs the image GoToSocial publishes
    (docker.io/superseriousbusiness/gotosocial), `latest` unless this project
    tracks a release tag. To move to a new version, change the branch or tag and
    redeploy; the database migrates itself on the next start.
  * Your instance host is fixed to the domain the project was created with. It is
    baked into every account and post address -- changing it later is not a
    supported move in GoToSocial.
  * Mail is unconfigured. GoToSocial needs it only for optional confirmation and
    notification emails; set the [smtp] config if you want them.
MDEOF
chmod 600 "${DATA_HOME}/README.panelalpha.md" 2>/dev/null || true

say "prepared"
