#!/bin/bash
set -e
cd ~/project

# Nothing in this checkout runs: the stack is the project's own published
# Community Edition image. The tag still comes from the checkout, so a clone of
# a 2.x branch gets a 2.x image rather than whatever `latest` is today. The
# repository carries no version constant (mix.exs reads APP_VERSION from the
# environment), so the first released heading in CHANGELOG.md is the version
# the tree belongs to. Plausible publishes vX, vX.Y and vX.Y.Z, so the major is
# the tag that keeps a redeploy on the same line without pinning a patch.
IMAGE_TAG=v3
MAJOR=$(sed -n 's/^## v\([0-9]\{1,\}\)\..*/\1/p' CHANGELOG.md 2>/dev/null | head -1)
if [ -n "${MAJOR}" ]; then
    GHCR_TOKEN=$(curl -fsS --max-time 20 'https://ghcr.io/token?scope=repository:plausible/community-edition:pull&service=ghcr.io' 2>/dev/null \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
    if [ -n "${GHCR_TOKEN}" ] && curl -fsS -o /dev/null --max-time 20 \
        -H "Authorization: Bearer ${GHCR_TOKEN}" \
        -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json' \
        "https://ghcr.io/v2/plausible/community-edition/manifests/v${MAJOR}" 2>/dev/null; then
        IMAGE_TAG="v${MAJOR}"
    fi
fi

# Each secret is written once and only once: the postgres volume and the TOTP
# secrets in it outlive the checkout, so a regenerated POSTGRES_PASSWORD would
# lock plausible out of its own database and a regenerated TOTP_VAULT_KEY would
# make every enrolled second factor undecryptable.
#
# SECRET_KEY_BASE signs the session cookies and must be at least 32 bytes
# (runtime.exs raises below that). TOTP_VAULT_KEY has a format requirement --
# base64 of exactly 32 bytes -- and anything else is rejected at boot with the
# same message this generates it with. Hex for the database password: it ends
# up inside DATABASE_URL, where a base64 `/` or `+` would have to be
# percent-encoded.
touch .env
keep() { grep -q "^$1=" .env || printf '%s=%s\n' "$1" "$2" >> .env; }
keep SECRET_KEY_BASE "$(openssl rand -base64 48 | tr -d '\n')"
keep TOTP_VAULT_KEY "$(openssl rand -base64 32 | tr -d '\n')"
keep POSTGRES_PASSWORD "$(openssl rand -hex 16)"
chmod 600 .env

# The image tag is the one line rewritten on every deploy, so a redeploy after
# an upstream release actually moves. The secrets above never do.
sed -i '/^PLAUSIBLE_IMAGE=/d' .env
printf 'PLAUSIBLE_IMAGE=%s\n' "ghcr.io/plausible/community-edition:${IMAGE_TAG}" >> .env
