#!/bin/bash
# Account shell, inside the account's own Docker daemon, cwd ~/project, after the
# clone and after overrides/docker-compose.yml has been laid down as
# ~/project/docker-compose.yml, before detection and before `up`.
#
# GoToSocial keeps its whole identity in the SQLite database (the instance
# account's keypair is a row in it), so unlike Readeck there is no external
# secret to pin -- the one job that matters is that the database directory
# survives a redeploy. Four things:
#   1. The administrator credentials -- generated once into ~/.panelalpha/, the
#      one directory a redeploy cannot reach.
#   2. The persistent storage directory (SQLite DB + media + instance keypair).
#   3. The image tag, from the checkout's git ref.
#   4. docker-compose.override.yml -- the account's uid/gid and the image.
set -e
cd ~/project

DATA_HOME="${HOME}/.panelalpha/gotosocial"
STORAGE="${DATA_HOME}/storage"
ENV_STORE="${DATA_HOME}/gotosocial.env"
NOTE="${DATA_HOME}/credentials.txt"
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
# 1 + 2. Persistent data and the administrator credentials.
#
# ~/project is emptied and re-cloned on every deploy (engine#173) and ~ is
# chowned root on every rebuild (Project.php); ~/.panelalpha is the only
# directory that both survives and belongs to the account. The SQLite database,
# the media store and the instance keypair all live under storage/, so a redeploy
# that lost it would regenerate the keypair and break the instance's federation
# identity for good.
mkdir -p "${STORAGE}"
chmod 700 "${HOME}/.panelalpha" "${DATA_HOME}"

if [ ! -f "${ENV_STORE}" ]; then
    ADMIN_USER=admin
    # No '/', '+' or '=': read back by a POSIX shell env_file and passed to
    # GoToSocial as GTS_PASSWORD. ~30 chars of base64 alphabet is far past
    # GoToSocial's 60-bit entropy floor (internal/validate password check).
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Read only by the one-shot init container, only to create the administrator
# when the instance has none. Never read by the running server.
PA_ADMIN_USER=${ADMIN_USER}
PA_ADMIN_PASSWORD=${ADMIN_PASSWORD}
PA_ADMIN_EMAIL=admin@localhost
EOF
    )
    (
        umask 077
        cat > "${NOTE}" <<EOF
GoToSocial administrator for this account
=========================================

  username: ${ADMIN_USER}
  password: ${ADMIN_PASSWORD}

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password inside GoToSocial, this file is out of date and the value in
the application wins -- nothing here overwrites it.

GoToSocial is invite-only (accounts-registration-open=false), so nobody can
create an account on your instance without an invite. Create further users from
Settings -> Administration, or with the CLI
(gotosocial admin account create ...). Sign in at:

    https://<your domain>/
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the administrator credentials in ${ENV_STORE}"
fi
chmod 600 "${ENV_STORE}" "${NOTE}" 2>/dev/null || true

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

username  `admin`
password  in the file `credentials.txt` beside this one

Change it and manage the instance under Settings -> Administration.

## What a stranger sees

The instance landing page and the public web profile/timeline of any account you
choose to make public. The client API refuses unauthenticated access to private
data. Signup is invite-only, so nobody can create an account without an invite.

## What lives where

    ~/.panelalpha/gotosocial/
      README.panelalpha.md   this file
      credentials.txt        the generated administrator password, 0600
      gotosocial.env         the credentials init uses on a fresh instance
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

say "prepared; credentials in ${NOTE}"
