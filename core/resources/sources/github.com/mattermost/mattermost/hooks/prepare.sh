#!/bin/bash
set -e
cd ~/project

# Guarded as a whole: the volumes outlive the checkout, so a regenerated
# POSTGRES_PASSWORD would lock Mattermost out of its own database and a
# regenerated admin password would be one nobody was ever told.
if [ -f .env ]; then
    exit 0
fi

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

# Mattermost has no installer and no first-boot admin environment variables.
# With zero users in the database, POST /api/v4/users is unauthenticated and the
# account it creates is given system_admin (app/user.go: the first account is),
# so on a public HTTPS name the owner of the instance is whoever finds it first.
# files/panelalpha-setup.sh makes that first request itself, with these.
MM_ADMIN_EMAIL=admin@example.com
MM_ADMIN_USERNAME=admin
# Alphanumeric on purpose: this value is interpolated by compose, embedded in a
# JSON body by the setup script and typed by a human. Mattermost's own default
# rule is 8 characters with no character-class requirement.
MM_ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-20)

cat > .env <<EOF
MATTERMOST_IMAGE=mattermost/mattermost-team-edition:${MATTERMOST_TAG}
POSTGRES_PASSWORD=$(openssl rand -hex 24)
MM_ADMIN_EMAIL=${MM_ADMIN_EMAIL}
MM_ADMIN_USERNAME=${MM_ADMIN_USERNAME}
MM_ADMIN_PASSWORD=${MM_ADMIN_PASSWORD}
EOF
chmod 600 .env

# Where the engine and the customer look for a generated credential. .env is the
# file compose interpolates; this is the one a human is pointed at.
cat > .panelalpha-admin-password <<EOF
# Written by PanelAlpha on the first deploy. Mattermost has no installer: the
# first account created becomes the system admin, so PanelAlpha creates it
# rather than leaving it to whoever opens the site first.
MATTERMOST_ADMIN_EMAIL=${MM_ADMIN_EMAIL}
MATTERMOST_ADMIN_USERNAME=${MM_ADMIN_USERNAME}
MATTERMOST_ADMIN_PASSWORD=${MM_ADMIN_PASSWORD}
EOF
chmod 600 .panelalpha-admin-password
