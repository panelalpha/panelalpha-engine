#!/bin/bash
# Generate Penpot's secret key and database password once into ~/.panelalpha
# (~/project is re-cloned on every deploy; the database volume keeps its password).
set -e
DATA="${HOME}/.panelalpha/penpot"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${DATA}/db.env" ]; then
    umask 077
    PG=$(openssl rand -hex 24)
    printf 'POSTGRES_PASSWORD=%s\n' "${PG}" > "${DATA}/db.env"
    cat > "${DATA}/penpot.env" <<ENVEOF
PENPOT_SECRET_KEY=$(openssl rand -base64 64 | tr -d '\n=' | tr '+/' '-_')
PENPOT_DATABASE_PASSWORD=${PG}
ENVEOF
    echo "[panelalpha] penpot: generated secrets in ${DATA}"
fi
chmod 600 "${DATA}"/*.env
