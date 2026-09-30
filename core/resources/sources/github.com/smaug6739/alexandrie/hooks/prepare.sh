#!/bin/bash
# Generate the JWT secret, MySQL passwords and RustFS keys once, outside
# ~/project, which is wiped every deploy.
set -e
DIR="${HOME}/.panelalpha/alexandrie"
mkdir -p "${DIR}"
chmod 700 "${HOME}/.panelalpha" "${DIR}"
if [ ! -f "${DIR}/backend.env" ]; then
    db="$(openssl rand -hex 24)"
    ak="$(openssl rand -hex 12)"
    sk="$(openssl rand -hex 24)"
    ( umask 077
      printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_PASSWORD=%s\n' "$(openssl rand -hex 24)" "${db}" > "${DIR}/mysql.env"
      printf 'RUSTFS_ACCESS_KEY=%s\nRUSTFS_SECRET_KEY=%s\n' "${ak}" "${sk}" > "${DIR}/rustfs.env"
      printf 'JWT_SECRET=%s\nDATABASE_PASSWORD=%s\nMINIO_ACCESSKEY=%s\nMINIO_SECRETKEY=%s\n' \
        "$(openssl rand -hex 32)" "${db}" "${ak}" "${sk}" > "${DIR}/backend.env" )
    echo "[alexandrie] generated the JWT secret, MySQL passwords and RustFS keys -> ${DIR}" >&2
fi
