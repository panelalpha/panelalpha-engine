#!/bin/bash
# Generates the database passwords once into ~/.panelalpha/ojs and creates the
# config.inc.php the app bind-mounts (filled from the image's template by the
# config-init service), so both survive redeploys.
set -e
DATA="${HOME}/.panelalpha/ojs"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    cat > "${ENV_FILE}" <<ENVEOF
# OJS installer, "Database settings": host db, username ojs, database ojs,
# password MARIADB_PASSWORD below.
MARIADB_PASSWORD=$(openssl rand -hex 16)
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
ENVEOF
    echo "[panelalpha] ojs: generated database passwords in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
# Must exist before `compose up`, or Docker creates a directory in its place.
[ -e "${DATA}/config.inc.php" ] || : > "${DATA}/config.inc.php"
