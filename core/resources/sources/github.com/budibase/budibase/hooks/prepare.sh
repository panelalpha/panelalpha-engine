#!/bin/bash
set -e
cd ~/project

# Guarded as a whole: couchdb_data and minio_data outlive the checkout, so a
# regenerated COUCH_DB_PASSWORD would lock Budibase out of its own database, a
# regenerated API_ENCRYPTION_KEY would make every stored datasource credential
# undecryptable, and a regenerated JWT_SECRET would log everyone out. The admin
# login is the engine's (`credentials:` in panelalpha.yaml), read by app-service
# from ~/.panelalpha/app-credentials.env.
if [ -f .env ]; then
    exit 0
fi

# There is no version in this checkout to pin an image to, and that is not an
# oversight of ours: lerna.json is `"version": "independent"`, every
# packages/*/package.json says "version": "0.0.0", and charts/budibase/Chart.yaml
# says `# populates on packaging` above both its version and its appVersion.
# The version is injected at release time as a --build-arg (every Dockerfile
# here ends with `RUN test -n "$BUDIBASE_VERSION"`), so a clone of master
# carries none.
#
# So the tag is upstream's own release channel rather than a derived number.
# `stable` and `latest` are the same digest today; `stable` is the one
# Budibase's docs point self-hosters at, and it is confirmed to exist before it
# is used, because a tag whose pull fails takes the whole deploy with it.
bb_tag() {
    local repo="$1"
    local tag
    for tag in stable latest; do
        if curl -fsS --max-time 15 -o /dev/null \
            "https://hub.docker.com/v2/repositories/budibase/${repo}/tags/${tag}" 2>/dev/null; then
            echo "${tag}"
            return
        fi
    done
    echo latest
}

BB_APPS_TAG=$(bb_tag apps)
BB_WORKER_TAG=$(bb_tag worker)
BB_PROXY_TAG=$(bb_tag proxy)

# The one version string in the tree that is real. hosting/couchdb/VERSION is
# the tag of the budibase/database image built from hosting/couchdb/, and
# charts/budibase/values.yaml pins the same number. CouchDB is the database, so
# this one is worth pinning rather than floating: the image carries Clouseau and
# SQS, and their on-disk layout is not something to have change under a
# redeploy. Falls back to latest if the tag is unreadable or unpublished.
BB_COUCHDB_TAG=latest
COUCHDB_VERSION=$(tr -d '[:space:]' < hosting/couchdb/VERSION 2>/dev/null || true)
if [ -n "${COUCHDB_VERSION}" ] \
    && curl -fsS --max-time 15 -o /dev/null \
        "https://hub.docker.com/v2/repositories/budibase/database/tags/${COUCHDB_VERSION}" 2>/dev/null; then
    BB_COUCHDB_TAG="${COUCHDB_VERSION}"
fi

# COUCH_DB_USER/PASSWORD are embedded in COUCH_DB_URL as userinfo, so hex
# rather than base64: no @, / or : to re-parse the URL around.
cat > .env <<EOF
BB_APPS_IMAGE=budibase/apps:${BB_APPS_TAG}
BB_WORKER_IMAGE=budibase/worker:${BB_WORKER_TAG}
BB_PROXY_IMAGE=budibase/proxy:${BB_PROXY_TAG}
BB_COUCHDB_IMAGE=budibase/database:${BB_COUCHDB_TAG}
COUCH_DB_USER=budibase
COUCH_DB_PASSWORD=$(openssl rand -hex 24)
REDIS_PASSWORD=$(openssl rand -hex 24)
MINIO_ACCESS_KEY=$(openssl rand -hex 12)
MINIO_SECRET_KEY=$(openssl rand -hex 24)
# Shared secret the app and worker authenticate to each other with.
INTERNAL_API_KEY=$(openssl rand -hex 32)
# Encrypts the credentials of every external datasource the customer connects.
# Rotating it after data exists makes those credentials unreadable.
API_ENCRYPTION_KEY=$(openssl rand -hex 32)
# Signs every session cookie and API token.
JWT_SECRET=$(openssl rand -hex 32)
EOF
chmod 600 .env
