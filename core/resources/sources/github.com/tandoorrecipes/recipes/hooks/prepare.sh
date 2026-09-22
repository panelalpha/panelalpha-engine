#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml and
# files/ have been written, before the build.
#
# Three things the compose file cannot do for itself: put this account's
# secrets somewhere the next clone will not delete, tell the owner where the
# administrator password is, and decide which published image tag to run.
set -e
cd ~/project

say() { echo "[tandoor] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL and a fork answers to the same one.
# Nothing below reads the checkout for anything but its git ref, but saying so
# turns a confusing result into one line in the deploy log.
if [ ! -f recipes/settings.py ] || [ ! -d vue3 ]; then
    say "WARNING: this does not look like TandoorRecipes/recipes"
fi

# ---------------------------------------------------------------------------
# 1. Secrets.
#
# engine#173: every deploy re-clones and ProjectTree::clearContents()
# (GitRepository.php:89) empties ~/project first, so a guard on a file in there
# never fires. Regenerating SECRET_KEY logs every session out; regenerating
# POSTGRES_PASSWORD locks the app out of the pgdata volume, which still holds
# the old one, and the deploy comes up on an authentication failure it cannot
# recover from. ProjectEnvironment::apply() also republishes ~/project/.env as
# .env.default at mode 644, so a database password may not be written there
# either.
#
# ~/.panelalpha/tandoor/ survives the clone (`find ~/project -mindepth 1` never
# reaches it) and is the only place in the account that does. 0600 in a 0700
# directory; delivered to the containers as a second env_file entry, which
# compose appends to the first.
STORE_DIR="${HOME}/.panelalpha/tandoor"
ENV_STORE="${STORE_DIR}/tandoor.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ENV_STORE}" ]; then
    ADMIN_USER=admin
    # No '/', '+' or '=' -- this value is read back by a POSIX shell, written
    # into an env file with no quoting, and typed into a login form.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated. Deleting
# this file does not reset the application: POSTGRES_PASSWORD is already
# stored in the pgdata volume and SECRET_KEY already signs the sessions in it.

# settings.py:49 falls back to the literal string
# INSECURE_STANDARD_KEY_SET_IN_ENV, which ships in every copy of the
# repository and signs every session cookie and password-reset token.
SECRET_KEY=${SECRET_KEY}

# Read by postgres on its first boot to create the role, and by Tandoor on
# every boot to connect as it.
POSTGRES_PASSWORD=${PG_PASSWORD}

# The account created by panelalpha/tandoor/init.sh before the web container
# starts, which is what closes the anonymous /setup/ superuser form.
TANDOOR_ADMIN_USER=${ADMIN_USER}
TANDOOR_ADMIN_PASSWORD=${ADMIN_PASSWORD}
TANDOOR_ADMIN_EMAIL=admin@localhost
EOF
    )
    (
        umask 077
        cat > "${NOTE}" <<EOF
Tandoor administrator for this account
======================================

  username: ${ADMIN_USER}
  password: ${ADMIN_PASSWORD}

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password inside Tandoor, this file is out of date and the one in the
application wins -- nothing here overwrites it.

Tandoor has no sign-up page by default (ENABLE_SIGNUP defaults to false in
recipes/settings.py:281). Invite further users from the application's own
space settings.
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the secrets in ${ENV_STORE}"
fi

# ---------------------------------------------------------------------------
# 2. Which image.
#
# Upstream publishes latest (releases only), beta, develop, a branch tag for
# every branch, and X, X.Y, X.Y.Z. docs/install/docker.md calls `latest` "the
# one you should use if you don't know that you need anything else" and warns,
# in a danger box, that there is no way to migrate a database back down -- so a
# branch image is never chosen implicitly. The repository's *default* branch is
# `develop`, which is exactly the tag upstream marks "not recommended", so
# following the checkout's branch would hand every plain `git clone` URL the
# bleeding-edge build.
#
# What the checkout does decide: a clone parked on a release tag gets that
# release. Everything else gets latest. An operator who wants beta or a pin
# sets TANDOOR_IMAGE in the account's env_vars, which merges over this file.
IMAGE_REPO=ghcr.io/tandoorrecipes/recipes
IMAGE_TAG=latest
GIT_TAG="$(git describe --exact-match --tags HEAD 2>/dev/null | tr -d '\n' || true)"
if [ -n "${GIT_TAG}" ]; then
    CANDIDATE="${GIT_TAG#v}"
    GHCR_TOKEN=$(curl -fsS --max-time 20 "https://ghcr.io/token?scope=repository:tandoorrecipes/recipes:pull&service=ghcr.io" 2>/dev/null \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
    if [ -n "${GHCR_TOKEN}" ] && curl -fsS -o /dev/null --max-time 20 \
        -H "Authorization: Bearer ${GHCR_TOKEN}" \
        -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json' \
        "https://ghcr.io/v2/tandoorrecipes/recipes/manifests/${CANDIDATE}" 2>/dev/null; then
        IMAGE_TAG="${CANDIDATE}"
    else
        say "checkout is at ${GIT_TAG} but ghcr.io has no such tag; using latest"
    fi
fi

# ~/project/.env is what compose interpolates ${TANDOOR_IMAGE} from, and what
# ProjectEnvironment::apply() merges the account's own env_vars over -- so an
# operator-set TANDOOR_IMAGE wins over this line. Nothing secret goes in here:
# the file is mode 644 and is copied to .env.default.
touch .env
sed -i '/^TANDOOR_IMAGE=/d' .env
printf 'TANDOOR_IMAGE=%s\n' "${IMAGE_REPO}:${IMAGE_TAG}" >> .env
say "image ${IMAGE_REPO}:${IMAGE_TAG}"
