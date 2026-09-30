#!/bin/bash
# Generates the MySQL passwords and eLabFTW's SECRET_KEY once, in ~/.panelalpha
# (survives redeploys; ~/project does not). A new SECRET_KEY would make the
# encrypted values already stored in the database unreadable.
set -e
STORE="${HOME}/.panelalpha/elabftw"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

if [ ! -f "${STORE}/db.env" ]; then
    printf 'MYSQL_PASSWORD=%s\nMYSQL_ROOT_PASSWORD=%s\n' \
        "$(openssl rand -hex 16)" "$(openssl rand -hex 16)" > "${STORE}/db.env"
fi
if [ ! -f "${STORE}/app.env" ]; then
    # defuse/php-encryption ASCII-safe key: hex(header DEF00000 . key . sha256(header . key))
    body="def00000$(openssl rand -hex 32)"
    sum=$(printf "$(printf '%s' "${body}" | sed 's/../\\x&/g')" | openssl dgst -sha256 -r | cut -d' ' -f1)
    printf 'SECRET_KEY=%s\nDB_PASSWORD=%s\n' "${body}${sum}" \
        "$(sed -n 's/^MYSQL_PASSWORD=//p' "${STORE}/db.env")" > "${STORE}/app.env"
fi
chmod 600 "${STORE}"/*.env
