#!/bin/bash
set -e
cd ~/project

# Tracim's first boot always creates the same administrator:
# InitializeDBCommand._populate_database() calls create_user(name="Global
# manager", username="TheAdmin", email="admin@admin.admin",
# password="admin@admin.admin"). files/panelalpha-setup.sh replaces that
# password as soon as the API answers, with the one the engine generated
# (`credentials:` in panelalpha.yaml): TRACIM_ADMIN_LOGIN / TRACIM_ADMIN_PASSWORD
# in ~/.panelalpha/app-credentials.env, the setup service's env_file. The login
# stays admin@admin.admin: changing the address is a second API call that can
# fail on its own, and the password is the secret, not the address.

# The image tag is a constant, which is unusual for these recipes and is the
# registry's fault rather than a shortcut. Upstream's README says it outright:
# "Docker images for the latest Tracim versions are only available to our
# paying customers". Docker Hub agrees — algoo/tracim's newest public release
# tag is 2025-04.00 and `latest` points at it, while this checkout's
# CHANGELOG.md is already on 2026.10.00. There is no public tag matching the
# tree, so deriving one from the checkout the way the Ghost and Mattermost
# recipes do would only produce a 404 at `up -d`. `unstable` is rebuilt from
# develop and would match, but it is named what it is.
#
# Rewritten every deploy, so changing this line in the recipe actually moves a
# redeployed account.
cat > .env <<EOF
TRACIM_IMAGE=algoo/tracim:latest
EOF
chmod 600 .env
