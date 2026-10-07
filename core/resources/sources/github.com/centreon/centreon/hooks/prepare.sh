#!/bin/bash
# Account shell, after the clone and before `docker compose up`.
#
# Everything here is a value neither the checkout nor the engine can supply:
# two database passwords and the answer files Centreon's unattended installer
# reads. The admin login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
# Nothing in the checkout is edited, patched or compiled.
set -e
cd ~/project

# The account's own state, and the only writable directory that survives a
# rebuild. ~/project does not: a git-project rebuild
# clears it and re-clones (DindDeployMechanics::ingestForWipeRebuild ->
# ProjectTree::clearContents), so a `[ ! -f ... ]` guard inside the checkout
# guards nothing -- it would mint a new database password on every redeploy
# while the data sat in a named volume under the old one.
STATE="${HOME}/.panelalpha/centreon"
mkdir -p "${STATE}"
chmod 700 "${STATE}"

if [ ! -f "${STATE}/secrets" ]; then
    # Hex and alphanumeric rather than base64: these travel through a compose
    # .env, a JSON file, a bash entrypoint and a shell-quoted CLI argument, and
    # a '$' or a quote would be a different bug in each of the four.
    DB_ROOT_PASSWORD=$(openssl rand -hex 24)
    DB_PASSWORD=$(openssl rand -hex 24)
    umask 077
    cat > "${STATE}/secrets" <<EOF
CENTREON_DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
CENTREON_DB_PASSWORD=${DB_PASSWORD}
EOF
fi

# shellcheck disable=SC1091
. "${STATE}/secrets"
set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a

# Centreon stores bcrypt in contact_password.password, and its unattended
# installer writes admin.json's value into that column *verbatim* -- which is
# why upstream's own admin.json holds a '$2y$10$...' string rather than a
# password. So the hash is what has to be generated here, with the account's
# own PHP (there is no bcrypt in coreutils). Kept while it still matches, so
# the image layer that copies it stays cached.
if ! P="${CENTREON_ADMIN_PASSWORD}" H="${STATE}/admin-hash" php -r 'exit(is_file(getenv("H")) && password_verify(getenv("P"), file_get_contents(getenv("H"))) ? 0 : 1);'; then
    (umask 077; P="${CENTREON_ADMIN_PASSWORD}" php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT);' > "${STATE}/admin-hash")
fi
ADMIN_HASH="$(cat "${STATE}/admin-hash")"

# The installer's answer files, rebuilt into the checkout on every deploy and
# never generated there. They replace
# .github/docker/centreon-web/alma9/configuration/{admin,database}.json, which
# hold Centreon's published defaults; files/Dockerfile.panelalpha's step 14
# copies these over them before step 15 reads them, and refuses to install if
# they are missing. The directory is bind-mounted read-only at /panelalpha,
# which is outside Apache's document root.
umask 077
mkdir -p panelalpha
# 0755 and not 0700: the engine scans ~/project after this hook and cannot
# open a directory it may not read -- "scandir
# (/home/<user>/project/panelalpha): Failed to open directory: Permission
# denied" fails the deploy outright. The files below are 0644 for the
# same reason; they are copied into an image layer in the same account either
# way, and ~/project/.env beside them holds the same database password.
chmod 755 panelalpha

# "localhost" on purpose: upstream's step 15 rewrites it to $MYSQL_HOST with a
# sed, and rewriting it here would leave that sed nothing to find.
cat > panelalpha/database.json <<EOF
{
  "address": "localhost",
  "port": "3306",
  "root_user": "root",
  "root_password": "${CENTREON_DB_ROOT_PASSWORD}",
  "db_configuration": "centreon",
  "db_storage": "centreon_storage",
  "db_user": "centreon",
  "db_password": "${CENTREON_DB_PASSWORD}",
  "db_password_confirm": "${CENTREON_DB_PASSWORD}"
}
EOF

cat > panelalpha/admin.json <<EOF
{
  "admin_password": "${ADMIN_HASH}",
  "confirm_password": "${ADMIN_HASH}",
  "firstname": "admin",
  "lastname": "admin",
  "email": "admin@example.com"
}
EOF

# Read by the replacement 20-configuration_files.sh, which runs Centreon's CLI
# as admin to generate and push the poller configuration. Upstream's version
# has the password written into it.
printf '%s' "${CENTREON_ADMIN_PASSWORD}" > panelalpha/admin-password
chmod 644 panelalpha/database.json panelalpha/admin.json panelalpha/admin-password

# What Compose interpolates. Only the two database passwords: the admin hash is
# full of '$' and a compose .env is interpolated before anything reads it,
# which is why it travels as a file instead.
cat > .env <<EOF
CENTREON_DB_ROOT_PASSWORD=${CENTREON_DB_ROOT_PASSWORD}
TZ=${TZ:-Etc/UTC}
EOF

# The base image, rewritten on every deploy so a redeploy picks up the series'
# current build. Pinned to the series (26.11) rather than to `develop`: the
# repository's own .version says MAJOR=26.11, the packages come from that
# series' repository, and a base image that walked onto 26.12 by itself would
# be a major upgrade performed by a redeploy.
#
# To move series, set CENTREON_BASE_IMAGE in the account's environment
# variables: ProjectEnvironment::apply() merges those onto this .env *after*
# this hook has written it, so an account-level value wins.
SERIES="${CENTREON_SERIES:-26.11}"
cat >> .env <<EOF
CENTREON_BASE_IMAGE=ghcr.io/centreon/centreon/centreon-web-dependencies-collect:${SERIES}-alma9
CENTREON_DB_IMAGE=mariadb:11.4
EOF

# files/ is copied in before this hook runs.
[ -f Dockerfile.panelalpha ] || { echo "[panelalpha] Dockerfile.panelalpha missing from the checkout" >&2; exit 1; }
