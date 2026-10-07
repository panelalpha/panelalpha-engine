#!/bin/bash
set -e
cd ~/project

say() { echo "[mattermost] $*" >&2; }

# The tag comes from the checkout rather than from a constant. server/public/
# model/version.go carries the release list "in chronological order with most
# current release at the front", so the first quoted x.y.z in it is the version
# this tree is working towards.
MM_MAJOR=$(sed -n 's/^[[:space:]]*"\([0-9]\{1,\}\)\.[0-9]\{1,\}\.[0-9]\{1,\}".*/\1/p' server/public/model/version.go | head -1)

# ...but master is the development branch, and its major usually has no general
# release yet: today version.go says 12.0.0 and the only 12 images on Docker Hub
# are 12.0.0-rc1 and the release-12 tag that points at it. So release-<major> is
# used only when a plain <major>.<minor>.<patch> tag exists for it — a real
# release, not a candidate. The quote before the number is what keeps 10.12.4
# and 5.12.6 out of the answer for major 12; the closing quote is what keeps
# 12.0.0-rc1 out.
MATTERMOST_TAG=latest
if [ -n "${MM_MAJOR}" ] && curl -fsS --max-time 20 \
    "https://hub.docker.com/v2/repositories/mattermost/mattermost-team-edition/tags?page_size=100&name=${MM_MAJOR}." 2>/dev/null \
    | grep -Eq "\"name\": ?\"${MM_MAJOR}\.[0-9]+\.[0-9]+\""; then
    MATTERMOST_TAG="release-${MM_MAJOR}"
fi

# The database password lives in ~/.panelalpha/mattermost/db.env, generated
# once: the postgres volume outlives the checkout, and ~/project (with its .env)
# is emptied before every deploy, so a password kept there would be
# regenerated while the volume still holds the first one. An account deployed
# before this kept it in ~/project/.env; one that still has it is carried over.
STORE_DIR="${HOME}/.panelalpha/mattermost"
DB_ENV="${STORE_DIR}/db.env"
mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${DB_ENV}" ]; then
    PW=$(sed -n 's/^POSTGRES_PASSWORD=//p' .env 2>/dev/null | head -1)
    [ -n "${PW}" ] || PW=$(openssl rand -hex 24)
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Written once on the first deploy; the postgres volume keeps this password.
POSTGRES_PASSWORD=${PW}
MM_SQLSETTINGS_DATASOURCE=postgres://mmuser:${PW}@postgres:5432/mattermost?sslmode=disable&connect_timeout=10
EOF
    )
    say "database password written to ${STORE_DIR}"
else
    say "reusing the database password in ${STORE_DIR}"
fi

# Only the image tag goes into .env, which the engine republishes at 644. The
# system admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env, which the setup service reads.
cat > .env <<EOF
MATTERMOST_IMAGE=mattermost/mattermost-team-edition:${MATTERMOST_TAG}
EOF
chmod 600 .env
