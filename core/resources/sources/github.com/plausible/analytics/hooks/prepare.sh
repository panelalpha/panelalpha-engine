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

# Secrets live in ~/.panelalpha/plausible, written once: ~/project is emptied on
# every deploy, while the Postgres volume keeps the password it was created with
# and a new TOTP_VAULT_KEY would make every enrolled second factor undecryptable.
# TOTP_VAULT_KEY must be base64 of exactly 32 bytes; the password is hex because
# it is embedded in DATABASE_URL.
STORE="${HOME}/.panelalpha/plausible"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
(
    umask 077
    if [ ! -s "${STORE}/db.env" ]; then
        printf 'POSTGRES_PASSWORD=%s\n' "$(openssl rand -hex 16)" > "${STORE}/db.env"
    fi
    if [ ! -s "${STORE}/app.env" ]; then
        PG_PASSWORD=$(sed -n 's/^POSTGRES_PASSWORD=//p' "${STORE}/db.env")
        {
            printf 'SECRET_KEY_BASE=%s\n' "$(openssl rand -base64 48 | tr -d '\n')"
            printf 'TOTP_VAULT_KEY=%s\n' "$(openssl rand -base64 32 | tr -d '\n')"
            printf 'DATABASE_URL=postgres://postgres:%s@plausible_db:5432/plausible_db\n' "${PG_PASSWORD}"
        } > "${STORE}/app.env"
    fi
)
chmod 600 "${STORE}/db.env" "${STORE}/app.env"

# .env only carries the image tag, rewritten on every deploy so a redeploy
# after an upstream release actually moves.
touch .env
sed -i '/^PLAUSIBLE_IMAGE=/d' .env
printf 'PLAUSIBLE_IMAGE=%s\n' "ghcr.io/plausible/community-edition:${IMAGE_TAG}" >> .env
