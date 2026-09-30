#!/bin/bash
# Generate the database password and Kinto's HMAC secrets once, outside
# ~/project (wiped every deploy): user ids and default bucket ids derive from them.
set -e
DIR="${HOME}/.panelalpha/kinto"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/app.env" ]; then
    rnd() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 40; }
    PW="$(rnd)"
    URL="postgresql://kinto:${PW}@db/kinto"
    ( umask 077; cat > "${DIR}/app.env" <<ENV
POSTGRES_PASSWORD=${PW}
KINTO_STORAGE_URL=${URL}
KINTO_PERMISSION_URL=${URL}
KINTO_CACHE_URL=${URL}
KINTO_USERID_HMAC_SECRET=$(rnd)
KINTO_DEFAULT_BUCKET_HMAC_SECRET=$(rnd)
ENV
    )
    echo "[kinto] generated database password and HMAC secrets -> ${DIR}/app.env" >&2
fi
