#!/bin/bash
set -e
cd ~/project

# Nothing in this checkout runs: the stack is the project's own published
# image. The tag still comes from the checkout, so a clone of tag v2.14.7 gets
# a 2.14 image rather than whatever `latest` is today. paperless-ngx publishes
# X.Y.Z, X.Y and latest -- there is no major-only tag -- and the default branch
# (dev) carries the *next* version, which is usually not published yet, so the
# derived tag is only used once the registry is known to have it.
IMAGE_TAG=latest
VERSION=$(sed -n 's/^__version__.*(\([0-9]\{1,\}\), *\([0-9]\{1,\}\).*/\1.\2/p' src/paperless/version.py 2>/dev/null | head -1)
if [ -n "${VERSION}" ]; then
    GHCR_TOKEN=$(curl -fsS --max-time 20 'https://ghcr.io/token?scope=repository:paperless-ngx/paperless-ngx:pull&service=ghcr.io' 2>/dev/null \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
    if [ -n "${GHCR_TOKEN}" ] && curl -fsS -o /dev/null --max-time 20 \
        -H "Authorization: Bearer ${GHCR_TOKEN}" \
        -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json' \
        "https://ghcr.io/v2/paperless-ngx/paperless-ngx/manifests/${VERSION}" 2>/dev/null; then
        IMAGE_TAG="${VERSION}"
    fi
fi

# The repository ships a root .env of its own (COMPOSE_PROJECT_NAME=paperless),
# which compose reads for interpolation -- so append to it rather than replace
# it. The value comes from ~/.panelalpha/paperless/secrets.env, generated once:
# ~/project, .env included, is emptied on every deploy, while the data volume
# outlives it, and a regenerated PAPERLESS_SECRET_KEY would log every session
# out.
#
# The admin's login is not generated here any more: the engine generates it
# (`credentials:` in panelalpha.yaml) and the webserver reads it from
# ~/.panelalpha/app-credentials.env. An account an older deploy of this recipe
# seeded keeps its PAPERLESS_ADMIN_USER / PAPERLESS_ADMIN_PASSWORD lines in
# secrets.env, which is where the engine adopts them from (`adopt_from:`), so
# the login it reports is the one that database already has.
STORE="${HOME}/.panelalpha/paperless"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
touch "${STORE}/secrets.env" .env
chmod 600 "${STORE}/secrets.env"
# First a value an older deploy left in .env (a rebuild that did not wipe),
# then a new one; either way the store has it from now on.
store() {
    grep -q "^$1=" "${STORE}/secrets.env" && return 0
    ( umask 077
      grep -m1 "^$1=." .env >> "${STORE}/secrets.env" || printf '%s=%s\n' "$1" "$2" >> "${STORE}/secrets.env" )
}
store PAPERLESS_SECRET_KEY "$(openssl rand -hex 32)"
sed -i "/^PAPERLESS_SECRET_KEY=/d" .env
grep -m1 "^PAPERLESS_SECRET_KEY=" "${STORE}/secrets.env" >> .env
# Never in .env: the compose file no longer interpolates them, and a stale
# copy there would only be a second, wrong place to read a password from.
sed -i '/^PAPERLESS_ADMIN_USER=/d; /^PAPERLESS_ADMIN_PASSWORD=/d' .env

# The image tag is the one line that is rewritten every deploy, so a redeploy
# after an upstream release actually moves. Secrets above never do.
sed -i '/^PAPERLESS_IMAGE=/d' .env
printf 'PAPERLESS_IMAGE=%s\n' "ghcr.io/paperless-ngx/paperless-ngx:${IMAGE_TAG}" >> .env
