#!/bin/bash
# Account shell, after the clone and before `docker compose up`.
#
# Nothing in this checkout runs. github.com/zabbix/zabbix is the autotools
# source tree for zabbix_server (C), the PHP frontend under ui/ and the schema
# under database/; it has no compose file, no Dockerfile and nothing that
# serves HTTP from its root -- which is exactly why a stock deploy 403s. The
# stack is the images Zabbix SIA publishes, and everything this hook writes is
# a value neither the checkout nor the engine can supply.
set -e
cd ~/project

# Everything this account must keep across deploys lives here, and nothing in
# ~/project does: a git-project rebuild clears ~/project and re-clones it
# (DindDeployMechanics::ingestForWipeRebuild -> ProjectTree::clearContents), so
# generating secrets into the checkout and guarding on `[ ! -f .env ]` would
# regenerate the database password on every redeploy while the data sat in a
# named volume under the old one. ~ itself is root-owned; ~/.panelalpha is
# created with the account and belongs to it.
STATE="${HOME}/.panelalpha/zabbix"
mkdir -p "${STATE}"
chmod 700 "${STATE}"

if [ ! -f "${STATE}/secrets" ]; then
    # Hex, not base64: these are interpolated by Compose, passed through a
    # bash entrypoint and embedded in SQL, and a '$' or a quote in any of them
    # would be a different bug in each place.
    DB_ROOT_PASSWORD=$(openssl rand -hex 24)
    DB_PASSWORD=$(openssl rand -hex 24)
    # The one a human types. Alphanumeric for the same reason, 20 characters.
    ADMIN_PASSWORD=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | cut -c1-20)
    # Never disclosed. guest is disabled by group membership in the shipped
    # data, but its hash is published like Admin's, and a customer who enables
    # the Guests group later should not thereby open an account whose password
    # is in create.sql.
    GUEST_PASSWORD=$(openssl rand -hex 24)

    # bcrypt, which is what Zabbix 6.0+ stores (users.passwd is a $2y$ hash;
    # before 6.0 it was MD5). The account's own PHP does it -- there is no
    # bcrypt in coreutils, and `openssl passwd` on OpenSSL 3 offers -1/-5/-6
    # and no -2. PASSWORD_BCRYPT is cost 10, matching the shipped rows.
    ADMIN_HASH=$(P="${ADMIN_PASSWORD}" php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT);')
    GUEST_HASH=$(P="${GUEST_PASSWORD}" php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT);')

    umask 077
    cat > "${STATE}/secrets" <<EOF
ZBX_DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
ZBX_DB_PASSWORD=${DB_PASSWORD}
EOF
    # Single-quoted on purpose: a bcrypt hash is full of '$' and this file is
    # sourced by files/panelalpha-credentials.sh inside the container. A hash
    # cannot contain a quote, so this is safe as well as necessary.
    cat > "${STATE}/admin-hashes" <<EOF
ADMIN_HASH='${ADMIN_HASH}'
GUEST_HASH='${GUEST_HASH}'
EOF
    # What the customer is handed. Zabbix has no sign-up page and no installer:
    # without this, the site comes up on a login form whose only account is
    # Admin/zabbix, and that pair is in every copy of create.sql on the
    # internet.
    cat > "${STATE}/credentials" <<EOF
# Written by PanelAlpha on the first deploy of this account.
# Zabbix ships Admin/zabbix in its database schema; these replace it.
ZABBIX_URL=/
ZABBIX_ADMIN_USER=Admin
ZABBIX_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
fi

# Copied into the checkout on every deploy, never generated there.
# .env is what Compose interpolates; the hashes are a separate file because a
# '$2y$10$...' in a .env is interpolated by Compose before anything reads it.
umask 077
cp "${STATE}/secrets" .env
cp "${STATE}/credentials" .panelalpha-admin-password
# 0644 and not 0600: this one is bind-mounted read-only into the credentials
# container, which runs as the image's uid 1997 and cannot be given ownership
# of a file the account created. It holds bcrypt hashes, not passwords, and
# never leaves the account.
cp "${STATE}/admin-hashes" .panelalpha-admin-hashes
chmod 644 .panelalpha-admin-hashes
# files/ is copied in before this hook runs; the mount needs it executable.
chmod 755 panelalpha-credentials.sh

# The frontend's page title and the name in its "Zabbix server" host entry,
# both cosmetic, from the account's own name.
printf 'ZBX_SERVER_NAME=%s\n' "$(hostname)" >> .env
printf 'TZ=%s\n' "${TZ:-Etc/UTC}" >> .env

# The image tags, rewritten on every deploy so a redeploy picks up patch
# releases within the series. Deliberately NOT derived from the checkout:
# configure.ac on master says 8.0.0rc1, a release candidate with no general
# release and no published image, and a major jump needs the server's own
# upgrade path rather than a redeploy. 7.0 is the current LTS (supported to
# 2029).
#
# To move series, set ZABBIX_SERVER_IMAGE and ZABBIX_WEB_IMAGE in the account's
# env vars: ProjectEnvironment::apply() merges those onto this .env *after*
# this hook has written it, so an account-level value wins over what is
# appended here. ZABBIX_SERIES is read from this hook's own environment and is
# for an operator running the hook by hand.
SERIES="${ZABBIX_SERIES:-7.0}"
cat >> .env <<EOF
ZABBIX_SERVER_IMAGE=zabbix/zabbix-server-mysql:alpine-${SERIES}-latest
ZABBIX_WEB_IMAGE=zabbix/zabbix-web-nginx-mysql:alpine-${SERIES}-latest
ZABBIX_AGENT_IMAGE=zabbix/zabbix-agent:alpine-${SERIES}-latest
ZABBIX_DB_IMAGE=mariadb:11.4
EOF
