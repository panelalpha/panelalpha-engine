#!/bin/bash
set -e
cd ~/project

# Secrets live outside ~/project, not in it. The engine wipes and re-clones
# ~/project on every deploy (engine#173), so a guard on a file there never
# fires on a redeploy -- it would regenerate the database password while the
# db_data volume still holds the old one, and regenerate an admin password
# nobody was ever told while the Users row still has the old hash. ~/.panelalpha
# survives the clone, so that is where the account's credentials are kept and
# where a human is pointed. The home directory is root-owned 0755, so the
# directory has to be created rather than assumed.
SECRETS_DIR="${HOME}/.panelalpha"
SECRETS="${SECRETS_DIR}/bluecherry.env"
mkdir -p "${SECRETS_DIR}"
chmod 700 "${SECRETS_DIR}" 2>/dev/null || true

if [ ! -f "${SECRETS}" ]; then
    # Bluecherry does not have an installer and does not have a first-run
    # wizard either. misc/sql/initial_data_mysql.sql, which bc_db_tool.sh loads
    # into every new database, inserts one row:
    #
    #   INSERT INTO Users (username, password, salt, ...) VALUES
    #     ('Admin', 'b22dec1d6cfa580962f3a3796a5dc6b3', '1234', ...)
    #
    # b22dec1d6cfa580962f3a3796a5dc6b3 is md5('bluecherry' . '1234'), and
    # www/lib/lib.php says so itself -- user::getInfo() flags
    # default_password when password == md5('bluecherry'.salt). So a fresh
    # instance on a public HTTPS name accepts Admin / bluecherry from anyone
    # who knows the product. The UI shows a dismissible banner and nothing
    # more. files/panelalpha-setup.sh replaces that row with these values.
    #
    # The salt column is char(4) and the application's own generator is
    # data::getRandomString(4) over [0-9a-z], so the salt matches that shape
    # exactly; the entropy is in the password, which is 20 alphanumerics --
    # passed through the container environment, embedded in a shell-quoted SQL
    # literal and typed by a human, so no punctuation.
    umask 077
    DB_ROOT_PASSWORD=$(openssl rand -hex 24)
    cat > "${SECRETS}" <<EOF
# Written by PanelAlpha on the first deploy of this account, and reused by
# every redeploy. Bluecherry ships a fixed default administrator
# (Admin / bluecherry) in its initial data; PanelAlpha replaces it with the
# credential below rather than leaving that door open.
#
# This file is also the compose stack's env_file -- overrides/docker-compose.yml
# reads it at ../.panelalpha/bluecherry.env, relative to the project directory
# -- so nothing in here is ever copied into ~/project. The two duplicated
# values at the bottom are the names the two images' own entrypoints read.
BLUECHERRY_ADMIN_USERNAME=Admin
BLUECHERRY_ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | cut -c1-20)
BLUECHERRY_ADMIN_SALT=$(openssl rand -hex 8 | tr -dc '0-9a-f' | cut -c1-4)
# The database account the application connects with.
BLUECHERRY_DB_PASSWORD=$(openssl rand -hex 24)
# The administrative account bc_db_tool.sh creates it with. MARIADB_ROOT_PASSWORD
# is what the mariadb image initialises the server from; MYSQL_ADMIN_PASSWORD is
# what actions/entrypoint.sh writes into /root/.my.cnf and connects as.
MARIADB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
MYSQL_ADMIN_PASSWORD=${DB_ROOT_PASSWORD}
EOF
    chmod 600 "${SECRETS}"
    unset DB_ROOT_PASSWORD
fi

# The tag is resolved from the checkout, then confirmed to exist, because a tag
# whose pull fails takes the whole deploy with it.
#
# debian/changelog is the only version string in this tree -- `bluecherry
# (3:3.1.15) bookworm ...`, an epoch and an upstream version. Docker Hub is
# published from this same repository by .github/workflows/docker-build.yml,
# but only some releases get a version tag: 3.1.9 and 3.1.0-rc8 are the only
# two among 36 tags, so a clone of master usually finds nothing and falls
# through.
#
# The fallback is `latest` and deliberately not `stable`: `stable` was last
# pushed 2024-04-06 and is 18 months behind `latest` (2025-10-11), so the name
# means the opposite of what it looks like here. `master` is newer still
# (2025-12-29) but is a rolling branch build, not a release.
BC_VERSION=$(sed -n '1s/^bluecherry (\([0-9]*:\)\?\([^)]*\)).*/\2/p' debian/changelog 2>/dev/null | tr -d '[:space:]')
BLUECHERRY_TAG=latest
if [ -n "${BC_VERSION}" ] \
    && curl -fsS --max-time 15 -o /dev/null \
        "https://hub.docker.com/v2/repositories/bluecherrydvr/bluecherry/tags/${BC_VERSION}" 2>/dev/null; then
    BLUECHERRY_TAG="${BC_VERSION}"
fi

# What compose interpolates, and the only thing that needs to: an image tag,
# which is public. Every secret reaches the containers through
# `env_file: ../.panelalpha/bluecherry.env` instead, and never enters the
# project at all.
#
# That is not tidiness. The engine copies ~/project/.env to
# ~/project/.env.default at mode 644 (ProjectEnvironment::apply, engine#173),
# and an account's home is root-owned 0755 with the project directory 0755
# inside it -- so anything written to .env is readable by every other account's
# uid on the host. Confirmed by reading one account's .env.default as another
# account's user. ~/.panelalpha is 0700 and the file inside it 0600.
cat > .env <<EOF
BLUECHERRY_IMAGE=bluecherrydvr/bluecherry:${BLUECHERRY_TAG}
EOF
chmod 600 .env
