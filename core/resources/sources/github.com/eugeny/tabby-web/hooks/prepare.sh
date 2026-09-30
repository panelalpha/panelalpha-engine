#!/bin/bash
# Generates the secrets once into ~/.panelalpha/tabby-web (survives redeploys;
# ~/project is re-cloned on every deploy).
set -e
DATA="${HOME}/.panelalpha/tabby-web"
ENV_FILE="${DATA}/secrets.env"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${ENV_FILE}" ]; then
    umask 077
    DBPW="$(openssl rand -hex 24)"
    cat > "${ENV_FILE}" <<ENVEOF
MARIADB_PASSWORD=${DBPW}
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
DATABASE_URL=mysql://tabby:${DBPW}@db/tabby
# Signs sessions: upstream's default is the same "django-insecure" everywhere.
DJANGO_SECRET_KEY=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] tabby-web: generated secrets in ${ENV_FILE}"
fi
chmod 600 "${ENV_FILE}"
