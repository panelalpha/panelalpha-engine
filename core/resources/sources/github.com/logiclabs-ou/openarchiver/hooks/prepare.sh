#!/bin/bash
# Generate Open Archiver's keys and service passwords once into ~/.panelalpha
# (~/project is re-cloned on every deploy; encrypted credentials need the same key).
set -e
DATA="${HOME}/.panelalpha/openarchiver"
mkdir -p "${DATA}"
chmod 700 "${HOME}/.panelalpha" "${DATA}"
if [ ! -s "${DATA}/app.env" ]; then
    umask 077
    PG=$(openssl rand -hex 24)
    RP=$(openssl rand -hex 24)
    MK=$(openssl rand -hex 24)
    printf 'POSTGRES_PASSWORD=%s\n' "${PG}" > "${DATA}/db.env"
    printf 'REDIS_PASSWORD=%s\n' "${RP}" > "${DATA}/valkey.env"
    printf 'MEILI_MASTER_KEY=%s\n' "${MK}" > "${DATA}/meili.env"
    cat > "${DATA}/app.env" <<ENVEOF
DATABASE_URL=postgresql://openarchiver:${PG}@postgres:5432/open_archive
REDIS_PASSWORD=${RP}
MEILI_MASTER_KEY=${MK}
JWT_SECRET=$(openssl rand -hex 32)
ENCRYPTION_KEY=$(openssl rand -hex 32)
ENVEOF
    echo "[panelalpha] openarchiver: generated secrets in ${DATA}"
fi
chmod 600 "${DATA}"/*.env
